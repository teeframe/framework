<?php

namespace Network\SnapItems;

use Network\NetworkMessages;

class ObjPickupItem extends AbstractPositionedSnapItem
{
    public function __construct(
        public int $x,
        public int $y,
        public int $type,
        public int $subType,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_PICKUP, x: $x, y: $y);
    }
    
    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->type,
            $this->subType,
        ];
    }
}
