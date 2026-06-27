<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjSpectatorInfoItem extends AbstractSnapItem
{
    public function __construct(
        public int $spectatorId,
        public int $x,
        public int $y,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_SPECTATOR);
    }

    public function getInts(): array
    {
        return [
            $this->spectatorId,
            $this->x,
            $this->y,
        ];
    }
}
