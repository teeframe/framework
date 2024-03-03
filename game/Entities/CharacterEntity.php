<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\Vector2;
use TeeFrame\Game\Tees\AbstractTee;

class CharacterEntity extends AbstractEntity
{
    public function __construct(public Vector2 $position)
    {
        parent::__construct(position: $position);
    }

    public function __destruct()
    {
        // ...
    }

    public function getHitBoxRadius(): int
    {
        return 28;
    }

    public function doTick(): void
    {
        // ...
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [

        ];
    }
}
