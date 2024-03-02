<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjProjectileItem extends AbstractPositionedSnapItem
{
    public function __construct(
        public int $x,
        public int $y,
        public int $velX,
        public int $velY,
        public int $type,
        public int $startTick,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_PROJECTILE, x: $x, y: $y);
    }
    
    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->velX,
            $this->velY,
            $this->type,
            $this->startTick,
        ];
    }
}