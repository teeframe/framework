<?php

namespace Network\Encoder\SnapItems;

use Network\Encoder\SnapItemEncoder;

class ObjPickupItem extends SnapItemEncoder
{
    public static function make(int $x, int $y, int $type, int $subType): static
    {
        return (new static(4))
            ->addInt($x)
            ->addInt($y)
            ->addInt($type)
            ->addInt($subType);
    }
}