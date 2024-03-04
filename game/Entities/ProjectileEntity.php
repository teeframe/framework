<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

class ProjectileEntity extends AbstractEntity
{
    protected int $startTick = -1;

    public function __construct(public Vector2 $position, public Vector2 $direction, public int $type)
    {
        parent::__construct(position: $position);
    }

    public function __destruct()
    {
        // ...
    }

    public function setWorld(AbstractWorld $world): void
    {
        parent::setWorld($world);

        $this->startTick = $world->getCurrentTick();
    }

    public function getHitBoxRadius(): int
    {
        return 0;
    }

    public function doTick(): void
    {
        // ...
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjProjectileItem(
                x: (int) $this->position->x,
                y: (int) $this->position->y,
                velX: (int) ($this->direction->x * 100),
                velY: (int) ($this->direction->y * 100),
                type: $this->type,
                startTick: $this->startTick
            ),
        ];
    }
}
