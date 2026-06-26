<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;

abstract class AbstractEntity implements SnapableObject, TickableObject
{
    /**
     * @var int[]
     */
    protected array $allocatedSnapIds = [];

    protected bool $toDestroy = false;

    public function __construct(protected AbstractWorld $world, protected Vector2 $position)
    {
    }

    public function getPosition(): Vector2
    {
        return $this->position;
    }

    public function setPosition(Vector2 $position): void
    {
        $this->position = $position;
    }

    abstract public function getHitBoxRadius(): int;

    abstract public function doTick(): void;

    public function isToDestroy(): bool
    {
        return $this->toDestroy;
    }

    public function markToDestroy(): void
    {
        $this->toDestroy = true;
    }

    /**
     * @return int[]
     */
    public function getAllocatedSnapIds(): array
    {
        return $this->allocatedSnapIds;
    }

    public function addAllocatedSnapId(int $id): void
    {
        $this->allocatedSnapIds[] = $id;
    }
}
