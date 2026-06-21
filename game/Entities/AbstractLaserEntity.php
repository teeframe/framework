<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjLaserItem;

/**
 * Abstract laser — game-mode agnostic skeleton.
 */
abstract class AbstractLaserEntity extends AbstractEntity
{
    public function __construct(
        public Vector2 $position,
        public Vector2 $direction,
        protected float $energy,
        protected int $owner,
    ) {
        parent::__construct(position: $position);
    }

    public function getHitBoxRadius(): int
    {
        return 0;
    }

    abstract public function doTick(): void;

    /**
     * @return \TeeFrame\Network\SnapItems\AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjLaserItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                fromX: (int) round($this->from->x),
                fromY: (int) round($this->from->y),
                startTick: $this->evalTick,
            ),
        ];
    }

    protected Vector2 $from;
    protected int $evalTick = 0;
}