<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventExplosionItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_EXPLOSION, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
        ];
    }
}