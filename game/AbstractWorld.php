<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\PingCommand;
use TeeFrame\Game\Commands\WhisperCommand;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Map\Map;
use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\World\SnapIdPool;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
use TeeFrame\Network\SnapItems\AbstractPositionedSnapItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
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

    protected EmptyGameController $gameController;

    protected EmptyVoteController $voteController;

    protected EmptyTuneController $tuneController;

    public function __construct(public string $identifier, protected TickHandler $tickHandler, protected Map $map, protected AbstractServerInstance $server)
    {
        $this->snapIdPool = new SnapIdPool;

        $this->bootCommands();
        $this->bootGameController();
        $this->bootVoteController();
        $this->bootTuneController();
    }

    protected function bootCommands(): void
    {
        $this->registerCommand(new WhisperCommand);
        $this->registerCommand(new PingCommand);
    }

    public function registerCommand(AbstractCommand $command): void
    {
        $this->commands[] = $command;
    }

    protected function bootGameController(): void
    {
        $this->gameController = new EmptyGameController($this->tickHandler);
    }

    protected function bootVoteController(): void
    {
        $this->voteController = new EmptyVoteController();
    }

    protected function bootTuneController(): void
    {
        $this->tuneController = new EmptyTuneController();
    }

    abstract public function getMotd(AbstractTee $requestingTee): string;

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

    public function getGameController(): EmptyGameController
    {
        return $this->gameController;
    }

    public function getVoteController(): EmptyVoteController
    {
        return $this->voteController;
    }

    public function getTuneController(): EmptyTuneController
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
                };
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
        }
    }

    private function handleChatMessage(AbstractTee $tee, ClSayChunk $chunk): void
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

    private function handleEmoticonMessage(AbstractTee $tee, ClEmoticonChunk $chunk): void
    {
        $chunk = new SvEmoticonChunk(
            clientId: $tee->teeIndex,
            emoticon: $chunk->emoticon,
        );

        foreach ($this->tees as $tee) {
            $this->server->sendToTee($this, $tee->teeIndex, $chunk);
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
    }

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        // TODO: DoSnapshot()

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
}
