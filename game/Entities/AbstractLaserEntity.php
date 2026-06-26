<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;

/**
 * Abstract laser — game-mode agnostic skeleton.
 */
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
     * @return \TeeFrame\Network\SnapItems\AbstractSnapItem[]
     */
    abstract public function doSnap(\TeeFrame\Game\Tees\AbstractTee $requestingTee): array;
}