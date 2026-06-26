<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

abstract class AbstractProjectileEntity extends AbstractEntity
{
    protected int $startTick = -1;
    protected int $lifeSpan;
    protected float $curvature;
    protected float $speed;
    protected Vector2 $startPos;

    public function __construct(
        AbstractWorld $world,
        public Vector2 $position,
        public Vector2 $direction,
        public int $type,
        public int $owner = -1,
    ) {
        parent::__construct(world: $world, position: $position);

        $this->startPos  = clone $position;
        $this->startTick = $world->getCurrentTick();
    }

    public function setTuning(float $speed, float $curvature, int $lifeSpan): void
    {
        $this->speed     = $speed;
        $this->curvature = $curvature;
        $this->lifeSpan  = $lifeSpan;
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