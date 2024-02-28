<?php

namespace Network\SnapItems;

use Network\NetworkMessages;

class ObjPickupItem extends AbstractSnapItem
{
    public function __construct(
        public int $x,
        public int $y,
        public int $type,
        public int $subType,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_PICKUP, integers: [
            $this->x,
            $this->y,
            $this->type,
            $this->subType,
        ]);
    }
}
