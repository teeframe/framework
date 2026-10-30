<?php

namespace TeeFrame\Game;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\PingCommand;
use TeeFrame\Game\Commands\WhisperCommand;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\Vote\VoteController;
use TeeFrame\Game\World\ClientMessageHandler;
use TeeFrame\Game\World\SnapIdPool;
use TeeFrame\Game\World\TuneController;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvSoundGlobalChunk;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\AbstractPositionedSnapItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjEventSpawnItem;
use TeeFrame\Server\AbstractServerInstance;

abstract class AbstractWorld implements SnapableObject, TickableObject
{
    /**
     * @var AbstractCommand[]
     */
    protected array $commands = [];

    /**
     * @var AbstractEntity[]
     */
    protected array $entities = [];

    /**
     * @var AbstractPositionedSnapItem[]
     */
    protected array $pendingEvents = [];

    /**
     * @var array<int, int> Event index => excluded tee index (client-predicted events).
     */
    protected array $excludedEventTeeIndexes = [];

    /**
     * @var AbstractTee[]
     */
    protected array $tees = [];

    /**
     * @var int[]
     */
    protected array $releasedTeeIndexes = [];

    protected SnapIdPool $snapIdPool;
    protected ClientMessageHandler $clientMessageHandler;
    protected AbstractGameController $gameController;
    protected VoteController $voteController;
    protected TuneController $tuneController;
    protected bool $paused                                   = false;
    protected bool $resetRequested                           = false;
    protected ?Map $pendingMap                               = null;
    protected ?AbstractGameController $pendingGameController = null;

    public function __construct(
        public string $identifier,
        protected TickHandler $tickHandler,
        protected Map $map,
        protected AbstractServerInstance $server,
        protected int $slotsLimit,
    ) {
        $this->snapIdPool           = new SnapIdPool;
        $this->clientMessageHandler = new ClientMessageHandler($this);

        $this->bootCommands();

        $this->bootVoteController();
        $this->bootTuneController();

        $this->bootGameController();

        $this->getGameController()->setWorld($this);

        $this->bootMap();
    }

    protected function bootMap(): void
    {
        if (($gameLayer = $this->getMap()->getGameLayer()) !== null) {
            $this->getGameController()->bootMap($gameLayer);
        }
    }

    abstract protected function bootGameController(): void;

    abstract public function getMotd(AbstractTee $requestingTee): string;

    abstract protected function getNewCharacterEntity(Vector2 $position): AbstractCharacterEntity;

    protected function bootCommands(): void
    {
        $this->registerCommand(new WhisperCommand);
        $this->registerCommand(new PingCommand);
    }

    public function registerCommand(AbstractCommand $command): void
    {
        $this->commands[] = $command;
    }

    protected function bootVoteController(): void
    {
        $this->voteController = new VoteController;
    }

    protected function bootTuneController(): void
    {
        $this->tuneController = new TuneController;
    }

    public function getCurrentTick(): int
    {
        return $this->tickHandler->get();
    }

    /**
     * @return array{string, int, int}
     */
    public function getMapInfo(): array
    {
        return [
            $this->map->getName(),
            $this->map->getCrc(),
            $this->map->getSize(),
        ];
    }

    public function getMap(): Map
    {
        return $this->map;
    }

    public function changeMap(string $mapName): void
    {
        $this->reload(mapName: $mapName);
    }

    public function changeGameMode(string $gameMode, ?string $mapName = null): void
    {
        $this->reload(mapName: $mapName, gameMode: $gameMode);
    }

    /** Queue a validated world reload, applied after vote processing. */
    public function reload(?string $mapName = null, ?string $gameMode = null): void
    {
        $map        = $this->pendingMap ?? $this->map;
        $controller = $this->pendingGameController;

        // Prepare changes separately so a failure leaves the active/queued state intact.
        if ($gameMode !== null) {
            $controller = clone ($controller ?? $this->gameController);
            $controller->setGameMode($gameMode);
        }

        if ($mapName !== null && ($mapName === '' || $mapName === '.' || $mapName === '..' || strpbrk($mapName, "/\\\0") !== false)) {
            throw new \RuntimeException('Invalid map name');
        }

        // A mode-only change can reuse the map; a bare reload re-reads its file.
        if ($mapName !== null || $gameMode === null) {
            if ($map->getPath() === '') {
                throw new \RuntimeException('Current map has no source path');
            }

            $path = $mapName === null ? $map->getPath() : dirname($map->getPath()).'/'.$mapName.'.map';
            $map  = new Map($path);
            if ($map->getGameLayer() === null) {
                throw new \RuntimeException('Map has no game layer');
            }
        }

        $this->pendingMap            = $map;
        $this->pendingGameController = $controller;
    }

    protected function performReload(Map $map): void
    {
        if ($this->voteController->isVoteRunning()) {
            $this->voteController->endVote($this);
        }

        foreach ($this->entities as $entity) {
            $entity->markToDestroy();
        }

        foreach ($this->tees as $tee) {
            if ($tee->character !== null) {
                $tee->character->alive = false;
                $tee->character        = null;
            }
        }

        // This is a map reset, not a disconnect: avoid death/leave notifications.
        $this->tees               = [];
        $this->releasedTeeIndexes = [];
        $this->entities           = [];
        $this->clearEvents();
        $this->snapIdPool     = new SnapIdPool;
        $this->paused         = false;
        $this->resetRequested = false;
        $this->map            = $map;

        if ($this->pendingGameController !== null) {
            $this->gameController        = $this->pendingGameController;
            $this->pendingGameController = null;
            $this->gameController->setWorld($this);
        }

        $this->gameController->resetMap();
        $this->bootMap();
        $this->server->reloadWorld($this);
    }

    /**
     * @return AbstractTee[]
     */
    public function getTees(): array
    {
        return $this->tees;
    }

    public function findTee(int $teeIndex): ?AbstractTee
    {
        return $this->tees[$teeIndex] ?? null;
    }

    public function findCharacter(int $teeIndex): ?AbstractCharacterEntity
    {
        foreach ($this->entities as $entity) {
            if ($entity instanceof AbstractCharacterEntity && $entity->alive && $entity->tee?->teeIndex === $teeIndex) {
                return $entity;
            }
        }

        return null;
    }

    public function trySetTeeName(AbstractTee $tee, string $name): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return false;
        }

        foreach ($this->tees as $existingTee) {
            if ($existingTee !== $tee && $existingTee->name === $trimmed) {
                return false;
            }
        }

        $tee->name = $trimmed;

        return true;
    }

    public function setTeeName(AbstractTee $tee, string $name): void
    {
        $cleanName = '';
        for ($i = 0, $len = strlen($name); $i < $len; $i++) {
            $cleanName .= $name[$i] < ' ' ? ' ' : $name[$i];
        }

        if ($this->trySetTeeName($tee, $cleanName)) {
            return;
        }

        for ($i = 1; ; $i++) {
            if ($this->trySetTeeName($tee, "({$i}){$cleanName}")) {
                return;
            }
        }
    }

    public function getSlotsLimit(): int
    {
        return $this->slotsLimit;
    }

    public function getServer(): AbstractServerInstance
    {
        return $this->server;
    }

    public function getGameController(): AbstractGameController
    {
        return $this->gameController;
    }

    public function getVoteController(): VoteController
    {
        return $this->voteController;
    }

    /**
     * @return AbstractCommand[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public function getTuneController(): TuneController
    {
        return $this->tuneController;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function setPaused(bool $paused): void
    {
        $this->paused = $paused;
    }

    public function isResetRequested(): bool
    {
        return $this->resetRequested;
    }

    public function resetGame(): void
    {
        $this->resetRequested = true;
    }

    protected function resetRound(int $currentTick): void
    {
        $survivingEntities = [];

        foreach ($this->entities as $entity) {
            $entity->reset();

            if ($entity->isToDestroy()) {
                $this->removeEntity($entity);
            } else {
                $survivingEntities[] = $entity;
            }
        }

        $this->entities = $survivingEntities;

        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }

            // Detach any lingering character reference
            $tee->character = null;

            $tee->score          = 0;
            $tee->scoreStartTick = $currentTick;
            $tee->respawnTick    = $currentTick + (int) (NetworkParams::TICKS_PER_SECOND / 2);

            if ($tee->team !== GameConstants::TEAM_SPECTATORS) {
                $tee->spawning = true;
            }
        }
    }

    public function broadcast(AbstractChunk $chunk): void
    {
        foreach ($this->tees as $tee) {
            // Vital sequence numbers and resend flags belong to each connection.
            $this->server->sendToTee($this, $tee->teeIndex, clone $chunk);
        }
    }

    public function createSoundGlobal(int $soundId): void
    {
        if ($soundId < 0) {
            return;
        }

        $this->broadcast(new SvSoundGlobalChunk(soundId: $soundId));
    }

    public function createSoundGlobalFor(int $soundId, int $teeIndex): void
    {
        if ($soundId < 0) {
            return;
        }

        $this->server->sendToTee($this, $teeIndex, new SvSoundGlobalChunk(soundId: $soundId));
    }

    public function addEvent(AbstractPositionedSnapItem $event, ?int $excludedTeeIndex = null): void
    {
        $this->pendingEvents[] = $event;

        if ($excludedTeeIndex !== null) {
            $this->excludedEventTeeIndexes[count($this->pendingEvents) - 1] = $excludedTeeIndex;
        }
    }

    public function clearEvents(): void
    {
        $this->pendingEvents           = [];
        $this->excludedEventTeeIndexes = [];
    }

    public function addEntity(AbstractEntity $entity): void
    {
        $this->entities[] = $entity;
    }

    /**
     * @return AbstractEntity[]
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    public function removeEntity(AbstractEntity $entity): void
    {
        $allocatedSnapIds = $entity->getAllocatedSnapIds();

        foreach ($allocatedSnapIds as $id) {
            $this->snapIdPool->freeId($id);
        }

        $this->entities = array_filter(
            $this->entities,
            fn (AbstractEntity $e) => $e !== $entity
        );
    }

    public function addTee(AbstractTee $tee): void
    {
        if (count($this->releasedTeeIndexes) > 0) {
            $index = array_pop($this->releasedTeeIndexes);
        } else {
            $index = count($this->tees);
        }

        $tee->setWorld($this, $index);

        // Select the initial team before registration so capacity checks see a new player.
        if ($tee instanceof PlayerTee) {
            $controller = $this->getGameController();
            $tee->team  = $controller->clampTeam($controller->getAutoTeam($index));
        }

        $this->tees[$index] = $tee;

        if ($tee instanceof PlayerTee) {
            $tee->spawning       = $tee->team !== GameConstants::TEAM_SPECTATORS;
            $tee->lastActionTick = $this->getCurrentTick();
            $tee->scoreStartTick = $this->getCurrentTick();

            $chunk = new SvChatChunk(
                team: 0,
                clientId: -1,
                text: "'{$tee->name}' entered and joined the game",
            );

            $this->broadcast($chunk);
        }
    }

    public function resetSpectatorsFollowing(int $teeIndex): void
    {
        foreach ($this->tees as $tee) {
            if ($tee instanceof PlayerTee && $tee->spectatorId === $teeIndex) {
                $tee->spectatorId = GameConstants::SPEC_FREEVIEW;
            }
        }
    }

    public function removeTee(AbstractTee $tee, ?string $reason = null): void
    {
        foreach ($this->tees as $index => $existingTee) {
            if ($existingTee !== $tee) {
                continue;
            }

            if ($existingTee->character !== null && $existingTee->character->alive) {
                $existingTee->character->die();
            }

            if ($existingTee instanceof PlayerTee) {
                $text = $reason !== null && $reason !== ''
                    ? "'{$tee->name}' has left the game ({$reason})"
                    : "'{$tee->name}' has left the game";

                $chunk = new SvChatChunk(
                    team: 0,
                    clientId: -1,
                    text: $text,
                );

                $this->broadcast($chunk);
            }

            $this->voteController->abortVoteOnDisconnect($index);
            $this->voteController->cancelVoteOnCreatorLeave($index);

            $this->resetSpectatorsFollowing($index);

            $this->releasedTeeIndexes[] = $index;
            unset($this->tees[$index]);

            $this->getGameController()->checkTeamBalance();
        }
    }

    public function onMessage(AbstractTee $tee, AbstractChunk $chunk): void
    {
        $this->clientMessageHandler->handle($tee, $chunk);
    }

    public function doTick(): void
    {
        $currentTick = $this->getCurrentTick();
        $this->applyPlayerInputs($currentTick);
        $this->getVoteController()->tick($this);

        // Finish vote processing before applying a queued reload.
        if ($this->pendingMap !== null) {
            $map              = $this->pendingMap;
            $this->pendingMap = null;
            $this->performReload($map);

            return;
        }

        $this->getGameController()->doTick();
        if ($this->resetRequested) {
            $this->resetRequested = false;
            $this->resetRound($currentTick);
        }

        if ($this->paused) {
            $this->tickPausedEntities();

            return;
        }

        $this->tickEntities();
        $this->tickPlayers($currentTick);
        $this->updateSpectatorViews();
    }

    protected function applyPlayerInputs(int $currentTick): void
    {
        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }

            $character = $tee->character;
            // Fire-to-respawn: a dead tee pressing fire requests a spawn.
            if ($character === null && isset($tee->inputs[$currentTick]) && ($tee->inputs[$currentTick]->fire & 1)) {
                $tee->spawning = true;
            }
            if ($character === null) {
                continue;
            }

            if (isset($tee->inputs[$currentTick])) {
                $character->onPredictedInput($tee->inputs[$currentTick]);
                unset($tee->inputs[$currentTick]);
            }
            $character->applyInput();
        }
    }

    protected function tickPausedEntities(): void
    {
        foreach ($this->entities as $entity) {
            $entity->tickPaused();
        }
    }

    protected function tickEntities(): void
    {
        foreach ($this->entities as $entity) {
            $entity->doTick();
            if ($entity->isToDestroy()) {
                $this->removeEntity($entity);
            }
        }
    }

    protected function tickPlayers(int $currentTick): void
    {
        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS || $tee->character !== null) {
                continue;
            }

            // CPlayer::Tick automatically requests a respawn after three seconds.
            if (! $tee->spawning && $tee->dieTick > 0 && $tee->dieTick + 3 * 50 <= $currentTick) {
                $tee->spawning = true;
            }
            if ($tee->spawning && $tee->respawnTick <= $currentTick) {
                $this->tryRespawnTee($tee);
            }
        }
    }

    protected function updateSpectatorViews(): void
    {
        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }
            if ($tee->team === GameConstants::TEAM_SPECTATORS && $tee->spectatorId !== GameConstants::SPEC_FREEVIEW) {
                if (($targetTee = $this->findTee($tee->spectatorId)) !== null) {
                    $tee->viewPosition = clone $targetTee->viewPosition;
                }
            }
        }
    }

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        // TODO: create snapshot for demo recording

        $gameControllerSnap = array_map(
            fn (AbstractSnapItem $snap) => $snap->setId(0),
            $this->getGameController()->doSnap($requestingTee)
        );

        return [
            ...$gameControllerSnap,
            ...$this->doPlayerSnap($requestingTee),
            ...$this->doEventSnap($requestingTee),
            ...$this->doEntitySnap($requestingTee),
        ];
    }

    /**
     * @return AbstractSnapItem[]
     */
    protected function doPlayerSnap(AbstractTee $requestingTee): array
    {
        $snaps = [];

        foreach ($this->tees as $i => $tee) {
            $teeSnaps = $tee->doSnap($requestingTee);

            foreach ($teeSnaps as $teeSnap) {
                $teeSnap->setId($i);
            }

            $snaps = [...$snaps, ...$teeSnaps];
        }

        return $snaps;
    }

    /**
     * @return AbstractSnapItem[]
     */
    protected function doEventSnap(AbstractTee $requestingTee): array
    {
        $snaps = [];

        // Spectators see all events (no distance culling), mirroring the
        // teeworlds 0.6 approach where free-view spectators need full visibility.
        // TODO: Implement "Cl_ShowDistance" instead
        $isSpectator = $requestingTee instanceof PlayerTee
            && $requestingTee->team === GameConstants::TEAM_SPECTATORS;

        foreach ($this->pendingEvents as $i => $event) {
            if (($this->excludedEventTeeIndexes[$i] ?? null) === $requestingTee->teeIndex) {
                continue;
            }

            if (! $isSpectator && $requestingTee->viewPosition->distance(new Vector2($event->x, $event->y)) > 1500) {
                continue;
            }

            $event->setId($i);

            $snaps[] = $event;
        }

        return $snaps;
    }

    /**
     * @return AbstractSnapItem[]
     */
    protected function doEntitySnap(AbstractTee $requestingTee): array
    {
        $snaps = [];

        // Spectators see all entities (no distance culling), mirroring the
        // teeworlds 0.6 approach where free-view spectators need full visibility.
        // TODO: Implement "Cl_ShowDistance" instead
        $isSpectator = $requestingTee instanceof PlayerTee
            && $requestingTee->team === GameConstants::TEAM_SPECTATORS;

        foreach ($this->entities as $entity) {
            if ($entity->isToDestroy()) {
                continue;
            }

            if (! $isSpectator && $requestingTee->viewPosition->distance($entity->getPosition()) > 1100) {
                continue;
            }

            $entitySnaps = $entity->doSnap($requestingTee);

            $fixedSnapId = $entity->getFixedSnapId();
            if ($fixedSnapId !== null) {
                foreach ($entitySnaps as $snap) {
                    $snap->setId($fixedSnapId);
                }
            } else {
                $entityAllocatedIds = $entity->getAllocatedSnapIds();

                while (count($entityAllocatedIds) < count($entitySnaps)) {
                    $entity->addAllocatedSnapId($entityAllocatedIds[] = $this->snapIdPool->allocId());
                }

                foreach ($entitySnaps as $i => $snap) {
                    $snap->setId($entityAllocatedIds[$i]);
                }
            }

            $snaps = [...$snaps, ...$entitySnaps];
        }

        return $snaps;
    }

    protected function tryRespawnTee(PlayerTee $tee): bool
    {
        $spawnPos = $this->getGameController()->canSpawn($this, $tee->team);
        if ($spawnPos === null) {
            return false;
        }

        $tee->spawning = false;

        $character = $this->getNewCharacterEntity(clone $spawnPos);
        $character->spawn($spawnPos, $tee);
        $this->addEntity($character);

        // CreatePlayerSpawn event (visual spawn effect)
        $this->addEvent(new ObjEventSpawnItem(
            x: (int) round($spawnPos->x),
            y: (int) round($spawnPos->y),
        ));

        return true;
    }
}
