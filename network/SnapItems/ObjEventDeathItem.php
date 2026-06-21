<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventDeathItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y, public int $clientId)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_DEATH, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->clientId,
        ];
    }
}
