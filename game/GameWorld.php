<?php

namespace Game;

use Game\Core\SnapableObject;
use Game\Core\SnapIdPool;
use Game\Core\Vector2;
use Game\Entities\AbstractEntity;
use Network\SnapItems\AbstractPositionedSnapItem;
use Network\SnapItems\AbstractSnapItem;

class GameWorld implements SnapableObject
{
    protected const MAX_EVENTS = 128;

    /**
     * @var AbstractEntity[]
     */
    protected array $entities = [];

    /**
     * @var AbstractPositionedSnapItem[]
     */
    protected array $pendingEvents = [];

    protected SnapIdPool $snapIdPool;

    public function __construct()
    {
        $this->snapIdPool = new SnapIdPool();
    }

    public function snapIdPool(): SnapIdPool
    {
        return $this->snapIdPool;
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
        $this->entities[] = $entity;
    }

    public function removeEntity(AbstractEntity $entity): void
    {
        $this->entities = array_filter(
            $this->entities,
            fn(AbstractEntity $e) => $e !== $entity
        );
    }

    public function tick(): void
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
            ...$this->doEventSnap($requestingPlayer),
            ...$this->doEntitySnap($requestingPlayer),
        ];
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
