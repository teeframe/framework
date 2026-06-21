<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventDamageIndItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y, public int $angle)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_DAMAGEIND, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->angle,
        ];
    }
}