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

    public function __construct(protected AbstractWorld $world, public Vector2 $position)
    {
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
