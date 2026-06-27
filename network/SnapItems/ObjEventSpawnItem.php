<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventSpawnItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_SPAWN, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
        ];
    }
}
