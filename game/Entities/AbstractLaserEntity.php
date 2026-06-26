<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractLaserEntity extends AbstractEntity
{
    public function __construct(
        AbstractWorld $world,
        public Vector2 $position,
        public Vector2 $direction,
        protected float $energy,
        protected int $owner,
    ) {
        parent::__construct(world: $world, position: $position);
    }

    public function getHitBoxRadius(): int
    {
        return 0;
    }

    abstract public function doTick(): void;

    /**
     * @return AbstractSnapItem[]
     */
    abstract public function doSnap(AbstractTee $requestingTee): array;
}
