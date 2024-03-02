<?php

namespace Game\Entities;

use Game\Core\Vector2;
use Game\Player;

class ProjectileEntity extends AbstractEntity
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

    protected function doRawSnap(Player $requestingPlayer): array
    {
        return [];
    }
}