<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjEventSoundWorldItem extends AbstractPositionedSnapItem
{
    public function __construct(public int $x, public int $y, public int $soundId)
    {
        parent::__construct(itemId: NetworkMessages::NETEVENTTYPE_SOUNDWORLD, x: $x, y: $y);
    }

    public function getInts(): array
    {
        return [
            $this->x,
            $this->y,
            $this->soundId,
        ];
    }
}