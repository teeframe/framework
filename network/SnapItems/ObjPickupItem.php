<?php

namespace Network\SnapItems;

use Network\RawPayload;

class ObjPickupItem extends AbstractSnapItem
{
    public function __construct(
        public int $x,
        public int $y,
        public int $type,
        public int $subType,
    ) {
        parent::__construct(itemId: 4);
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addInt($this->x)
            ->addInt($this->y)
            ->addInt($this->type)
            ->addInt($this->subType)
            ->getPayload();
    }
}
