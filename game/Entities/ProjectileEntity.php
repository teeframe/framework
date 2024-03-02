<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\Core\Vector2;
use TeeFrame\Game\GameWorld;
use TeeFrame\Game\Player;
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

    public function setWorld(GameWorld $world): void
    {
        parent::setWorld($world);

        $this->startTick = $world->getCurrentTick();
    }

    public function tick(): void
    {
        // ...
    }

    public function getHitBoxRadius(): int
    {
        return 0;
    }

    protected function doRawSnap(Player $requestingPlayer): array
    {
        return [
            new ObjProjectileItem(
                x: (int) $this->position->x,
                y: (int) $this->position->y,
                velX: (int) ($this->direction->x * 100),
                velY: (int) ($this->direction->y * 100),
                type: $this->type,
                startTick: $this->startTick
            )
        ];
    }
}