<?php

namespace Network\Encoder\Chunks\Snap;

use Network\Encoder\PackageChunkSnapEncoder;

class ObjPickupItem extends PackageChunkSnapEncoder
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