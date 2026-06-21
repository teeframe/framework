<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjLaserItem extends AbstractPositionedSnapItem
{
    public function __construct(
        public int $x,
        public int $y,
        public int $fromX,
        public int $fromY,
        public int $startTick,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_LASER, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->fromX,
            $this->fromY,
            $this->startTick,
        ];
    }
}