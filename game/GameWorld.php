<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Core\SnapIdPool;
use TeeFrame\Game\Core\TickHandler;
use TeeFrame\Game\Core\Vector2;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Network\SnapItems\AbstractPositionedSnapItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

class GameWorld implements SnapableObject
{
    protected const MAX_EVENTS = 128; // TODO: Is this limit also on client? If not, this can be removed

    /**
     * @var AbstractEntity[]
     */
    protected array $entities = [];

    /**
     * @var AbstractPositionedSnapItem[]
     */
    protected array $pendingEvents = [];

    protected SnapIdPool $snapIdPool;

    public function __construct(protected TickHandler $tickHandler, protected GameController $controller)
    {
        $this->snapIdPool = new SnapIdPool();
    }

    public function getCurrentTick(): int
    {
        return $this->tickHandler->get();
    }

    public function snapIdPool(): SnapIdPool
    {
        return $this->snapIdPool;
    }

    public function controller(): GameController
    {
        return $this->controller;
    }

    public function addEvent(AbstractPositionedSnapItem $event): void
    {
        if (count($this->pendingEvents) >= self::MAX_EVENTS) {
            throw new \RuntimeException('Too many pending events');
        }

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

    public function removeEntity(AbstractEntity $entity): void
    {
        $allocatedSnapIds = $entity->getAllocatedSnapIds();

        foreach ($allocatedSnapIds as $id) {
            $this->snapIdPool->freeId($id);
        }

        $this->entities = array_filter(
            $this->entities,
            fn(AbstractEntity $e) => $e !== $entity
        );
    }

    public function onTick(): void
    {
        foreach ($this->entities as $entity) {
            $entity->tick();

            if ($entity->isToDestroy()) {
                $this->removeEntity($entity);
            }
        }
    }

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(Player $requestingPlayer): array
    {
        return [
            ...$this->controller()->doSnap($requestingPlayer),
            ...$this->doPlayerSnap($requestingPlayer),
            ...$this->doEventSnap($requestingPlayer),
            ...$this->doEntitySnap($requestingPlayer),
        ];
    }

    /**
     * @return AbstractSnapItem[]
     */
    protected function doPlayerSnap(Player $requestingPlayer): array
    {
        $snaps = [];


        return $snaps;
    }

    /**
     * @return AbstractSnapItem[]
     */
    protected function doEventSnap(Player $requestingPlayer): array
    {
        $snaps = [];

        foreach ($this->pendingEvents as $i => $event) {
            if ($requestingPlayer->viewPosition->distance(new Vector2($event->x, $event->y)) > 1500) {
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
    protected function doEntitySnap(Player $requestingPlayer): array
    {
        $snaps = [];

        foreach ($this->entities as $entity) {
            if ($entity->isToDestroy()) {
                continue;
            }

            if ($requestingPlayer->viewPosition->distance($entity->position) > 1100) {
                continue;
            }

            $entitySnaps        = $entity->doSnap($requestingPlayer);
            $entityAllocatedIds = $entity->getAllocatedSnapIds();

            while (count($entityAllocatedIds) < count($entitySnaps)) {
                $entity->addAllocatedSnapId($entityAllocatedIds[] = $this->snapIdPool->allocId());
            }

            foreach ($entitySnaps as $i => $snap) {
                $snap->setId($entityAllocatedIds[$i]);
            }

            $snaps = array_merge($snaps, $entitySnaps);
        }

        return $snaps;
    }
}
