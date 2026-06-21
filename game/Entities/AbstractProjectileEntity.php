<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

abstract class AbstractProjectileEntity extends AbstractEntity
{
    public const WEAPON_GUN     = 1;
    public const WEAPON_SHOTGUN = 2;
    public const WEAPON_GRENADE = 3;

    protected int $startTick = -1;
    protected int $lifeSpan;
    protected float $curvature;
    protected float $speed;
    protected Vector2 $startPos;

    public function __construct(
        public Vector2 $position,
        public Vector2 $direction,
        public int $type,
    ) {
        parent::__construct(position: $position);

        $this->startPos = clone $position;
    }

    public function setTuning(float $speed, float $curvature, int $lifeSpan): void
    {
        $this->speed     = $speed;
        $this->curvature = $curvature;
        $this->lifeSpan  = $lifeSpan;
    }

    public function setWorld(AbstractWorld $world): void
    {
        parent::setWorld($world);

        $this->startTick = $world->getCurrentTick();
    }

    public function getHitBoxRadius(): int
    {
        return 6;
    }

    abstract public function doTick(): void;

    abstract protected function getPos(float $time): Vector2;

    /**
     * @return \TeeFrame\Network\SnapItems\AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjProjectileItem(
                x: (int) $this->startPos->x,
                y: (int) $this->startPos->y,
                velX: (int) ($this->direction->x * 100),
                velY: (int) ($this->direction->y * 100),
                type: $this->type,
                startTick: $this->startTick,
            ),
        ];
    }
}
