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
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\SnapIdPool;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\ClKillChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
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
     * @var AbstractTee[]
     */
    protected array $tees = [];

    /**
     * @var int[]
     */
    protected array $releasedTeeIndexes = [];

    protected SnapIdPool $snapIdPool;

    protected AbstractGameController $gameController;

    protected VoteController $voteController;

    protected TuneController $tuneController;

    public function __construct(
        public string $identifier,
        protected TickHandler $tickHandler,
        protected Map $map,
        protected AbstractServerInstance $server
    ) {
        $this->snapIdPool = new SnapIdPool;

        $this->bootCommands();

        $this->bootVoteController();
        $this->bootTuneController();

        $this->bootGameController();
        $this->collectSpawnPoints();
    }

    abstract protected function bootGameController(): void;

    abstract public function getMotd(AbstractTee $requestingTee): string;

    protected function bootCommands(): void
    {
        $this->registerCommand(new WhisperCommand);
        $this->registerCommand(new PingCommand);
    }

    public function registerCommand(AbstractCommand $command): void
    {
        $this->commands[] = $command;
    }

    protected function collectSpawnPoints(): void
    {
        $gameLayer = $this->map->getGameLayer();

        if ($gameLayer !== null) {
            $this->gameController->collectSpawnPoints($gameLayer);
        }
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

    /**
     * @return AbstractTee[]
     */
    public function getTees(): array
    {
        return $this->tees;
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

    public function getTuneController(): TuneController
    {
        return $this->tuneController;
    }

    public function addEvent(AbstractPositionedSnapItem $event): void
    {
        $this->pendingEvents[] = $event;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
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

        $this->tees[$index] = $tee;

        if ($tee instanceof PlayerTee) {
            $tee->spawning = true;

            $chunk = new SvChatChunk(
                team: 0,
                clientId: -1,
                text: "'{$tee->name}' entered and joined the game",
            );

            foreach ($this->tees as $existingTee) {
                $this->server->sendToTee($this, $existingTee->teeIndex, $chunk);
            }
        }
    }

    public function removeTee(AbstractTee $tee, ?string $reason = null): void
    {
        foreach ($this->tees as $index => $existingTee) {
            if ($existingTee !== $tee) {
                continue;
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

                foreach ($this->tees as $existingTee) {
                    $this->server->sendToTee($this, $existingTee->teeIndex, $chunk);
                }
            }

            $this->releasedTeeIndexes[] = $index;
            unset($this->tees[$index]);
        }
    }

    public function onMessage(AbstractTee $tee, AbstractChunk $chunk): void
    {
        if ($chunk instanceof ClSayChunk) {
            $this->handleChatMessage($tee, $chunk);
        } elseif ($chunk instanceof ClEmoticonChunk) {
            $this->handleEmoticonMessage($tee, $chunk);
        } elseif ($chunk instanceof ClKillChunk) {
            $this->handleKillMessage($tee, $chunk);
        }
    }

    public function doTick(): void
    {
        // apply new input
        $currentTick = $this->getCurrentTick();
        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }

            $character = $tee->character;

            // Fire-to-respawn: a dead tee pressing fire requests a spawn
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

        // TODO: Implement GameServer()->OnTick()

        $this->getGameController()->doTick();

        foreach ($this->entities as $entity) {
            $entity->doTick();

            if ($entity->isToDestroy()) {
                $this->removeEntity($entity);
            }
        }

        // Player tick: handle respawns (CPlayer::Tick)
        foreach ($this->tees as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }

            if ($tee->character === null) {
                // Auto-respawn after 3s of being dead
                // (CPlayer::Tick: !m_pCharacter && m_DieTick+TickSpeed*3 <= Tick)
                if (! $tee->spawning && $tee->dieTick > 0 && $tee->dieTick + 3 * 50 <= $currentTick) {
                    $tee->spawning = true;
                }

                // Try to respawn once the respawn tick has elapsed
                // (CPlayer::Tick: m_Spawning && m_RespawnTick <= Tick)
                if ($tee->spawning && $tee->respawnTick <= $currentTick) {
                    $this->tryRespawnTee($tee);
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

        foreach ($this->pendingEvents as $i => $event) {
            if ($requestingTee->viewPosition->distance(new Vector2($event->x, $event->y)) > 1500) {
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

        foreach ($this->entities as $entity) {
            if ($entity->isToDestroy()) {
                continue;
            }

            if ($requestingTee->viewPosition->distance($entity->getPosition()) > 1100) {
                continue;
            }

            $entitySnaps = $entity->doSnap($requestingTee);

            // Character entities must use the tee's index as snap ID
            if ($entity instanceof AbstractCharacterEntity && $entity->tee !== null && $entity->tee->teeIndex >= 0) {
                foreach ($entitySnaps as $snap) {
                    $snap->setId($entity->tee->teeIndex);
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

    protected function handleChatMessage(AbstractTee $tee, ClSayChunk $chunk): void
    {
        $message = trim($chunk->text);

        if ($message === '') {
            return;
        }

        // Spam protection: max ~16 characters per second (CPlayer::m_LastChat)
        if ($tee instanceof PlayerTee) {
            $currentTick = $this->getCurrentTick();

            if ($tee->lastChatTick > 0 && $tee->lastChatTick + (int) (50 * ((15 + strlen($message)) / 16)) > $currentTick) {
                return;
            }

            $tee->lastChatTick = $currentTick;
        }

        // Check for registered commands
        foreach ($this->commands as $command) {
            if (preg_match($command->getPattern(), $message, $matches)) {
                $command->execute($this, $tee, $matches);

                return;
            }
        }

        // Send chat message
        $chunk = new SvChatChunk(
            team: $chunk->team ? 1 : 0,
            clientId: $tee->teeIndex, // From
            text: $message,
        );

        foreach ($this->tees as $tee) {
            $this->server->sendToTee($this, $tee->teeIndex, $chunk);
        }
    }

    protected function handleEmoticonMessage(AbstractTee $tee, ClEmoticonChunk $chunk): void
    {
        $chunk = new SvEmoticonChunk(
            clientId: $tee->teeIndex,
            emoticon: $chunk->emoticon,
        );

        foreach ($this->tees as $tee) {
            $this->server->sendToTee($this, $tee->teeIndex, $chunk);
        }
    }

    protected function handleKillMessage(AbstractTee $tee, ClKillChunk $chunk): void
    {
        if (! $tee instanceof PlayerTee) {
            return;
        }

        $currentTick = $this->getCurrentTick();

        // Kill cooldown: 3 seconds (CPlayer::m_LastKill + TickSpeed()*3)
        if ($tee->lastKillTick > 0 && $tee->lastKillTick + NetworkParams::TICKS_PER_SECOND * 3 > $currentTick) {
            return;
        }

        $tee->lastKillTick = $currentTick;

        $character = $tee->character;
        if ($character === null) {
            return;
        }

        // Self-kill: pass WEAPON_SELF so die() applies the 3s respawn penalty
        // and the kill message reports the correct weapon.
        $character->die($tee->teeIndex, GameConstants::WEAPON_SELF);
    }

    protected function tryRespawnTee(PlayerTee $tee): bool
    {
        $spawnPos = $this->getGameController()->canSpawn($this, 0);
        if ($spawnPos === null) {
            return false;
        }

        $tee->spawning = false;

        $character = new PvpCharacterEntity($this, clone $spawnPos);
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
