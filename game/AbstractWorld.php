<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Core\SnapIdPool;
use TeeFrame\Game\Core\TickableObject;
use TeeFrame\Game\Core\TickHandler;
use TeeFrame\Game\Core\Vector2;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\AbstractPositionedSnapItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractWorld implements SnapableObject, TickableObject
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

    /**
     * @var AbstractTee[]
     */
    protected array $tees = [];

    protected SnapIdPool $snapIdPool;

    protected EmptyWorldController $controller;

    public function __construct(public string $identifier, protected TickHandler $tickHandler)
    {
        $this->snapIdPool = new SnapIdPool;

        $this->bootController();
    }

    protected function bootController(): void
    {
        $this->controller = new EmptyWorldController($this);
    }

    public function getCurrentTick(): int
    {
        return $this->tickHandler->get();
    }

    public function snapIdPool(): SnapIdPool
    {
        return $this->snapIdPool;
    }

    public function controller(): EmptyWorldController
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
            fn (AbstractEntity $e) => $e !== $entity
        );
    }

    public function addTee(AbstractTee $tee): void
    {
        $tee->setWorld($this, count($this->tees));

        $this->tees[] = $tee;
    }

    public function removeTee(AbstractTee $tee): void
    {
        $this->tees = array_filter(
            $this->tees,
            fn (AbstractTee $t) => $t !== $tee
        );
    }

    public function doTick(): void
    {
        // TODO: apply new input

        // TODO: Implement GameServer()->OnTick()

        $this->controller()->doTick();

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

        return [
            ...array_map(fn (AbstractSnapItem $snap) => $snap->setId(0), $this->controller()->doSnap($requestingTee)),
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

            $entitySnaps        = $entity->doSnap($requestingTee);
            $entityAllocatedIds = $entity->getAllocatedSnapIds();

            while (count($entityAllocatedIds) < count($entitySnaps)) {
                $entity->addAllocatedSnapId($entityAllocatedIds[] = $this->snapIdPool->allocId());
            }

            foreach ($entitySnaps as $i => $snap) {
                $snap->setId($entityAllocatedIds[$i]);
            }

            $snaps = [...$snaps, ...$entitySnaps];
        }

        return $snaps;
    }
}
