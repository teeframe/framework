<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventHammerHitItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_HAMMERHIT, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
        ];
    }
}
