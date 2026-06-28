<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjFlagItem extends AbstractPositionedSnapItem
{
    public function __construct(int $x, int $y, public int $team)
    {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_FLAG, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->team,
        ];
    }
}
