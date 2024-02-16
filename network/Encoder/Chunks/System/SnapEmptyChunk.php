<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Protocol;

class SnapEmptyChunk extends PackageChunkEncoder
{
    public static function make(int $currentTick, int $deltaTick): static
    {
        return (new static(0, Protocol::SNAPEMPTY))
            ->addInt($currentTick)
            ->addInt($deltaTick);
    }
}
