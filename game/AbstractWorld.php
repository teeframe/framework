<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Entities\AbstractCharacterEntity;
use TeeFrame\Map\Map;
use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\World\SnapIdPool;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\AbstractPositionedSnapItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractWorld implements SnapableObject, TickableObject
{
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

    public function __construct(public string $identifier, protected TickHandler $tickHandler, protected Map $map)
    {
        $this->snapIdPool = new SnapIdPool;

        $this->bootGameController();
        $this->bootVoteController();
        $this->bootTuneController();
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

    public function gameController(): EmptyGameController
    {
        return $this->gameController;
    }

    public function voteController(): EmptyVoteController
    {
        return $this->voteController;
    }

    public function tuneController(): EmptyTuneController
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
        $entity->setWorld($this);

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

        // TODO: GameServer()->OnClientEnter(ClientID)
    }

    public function removeTee(AbstractTee $tee): void
    {
        foreach ($this->tees as $index => $existingTee) {
            if ($existingTee !== $tee) {
                continue;
            }

            $this->releasedTeeIndexes[] = $index;

            unset($this->tees[$index]);
        }
    }

    public function doTick(): void
    {
        // TODO: apply new input

        // TODO: Implement GameServer()->OnTick()

        $this->gameController()->doTick();

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
            $this->gameController()->doSnap($requestingTee)
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

            if ($requestingTee->viewPosition->distance($entity->position) > 1100) {
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
