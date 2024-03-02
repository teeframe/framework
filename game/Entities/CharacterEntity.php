<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\Core\Vector2;
use TeeFrame\Game\Player;

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

    public function tick(): void
    {
        // ...
    }

    public function getHitBoxRadius(): int
    {
        return 28;
    }

    protected function doRawSnap(Player $requestingPlayer): array
    {
        return [
            
        ];
    }
}