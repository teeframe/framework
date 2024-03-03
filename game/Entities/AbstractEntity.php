<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Core\Vector2;

abstract class AbstractEntity implements SnapableObject
{
    /**
     * @var int[]
     */
    protected array $allocatedSnapIds = [];

    protected bool $toDestroy = false;

    protected ?AbstractWorld $world = null;

    public function __construct(public Vector2 $position) 
    {
    }

    abstract public function getHitBoxRadius(): int;

    abstract public function doTick(): void;

    public function setWorld(AbstractWorld $world): void
    {
        $this->world = $world;
    }

    public function isToDestroy(): bool
    {
        return $this->toDestroy;
    }

    public function markToDestroy(): void
    {
        $this->toDestroy = true;
    }

    public function getAllocatedSnapIds(): array
    {
        return $this->allocatedSnapIds;
    }

    public function addAllocatedSnapId(int $id): void
    {
        $this->allocatedSnapIds[] = $id;
    }
}