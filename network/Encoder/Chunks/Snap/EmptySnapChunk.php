<?php

namespace Network\Encoder\Chunks\Snap;

use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Protocol;

class EmptySnapChunk extends PackageChunkEncoder
{
    public static function make(int $currentTick, int $deltaTick): static
    {
        return (new static(0, Protocol::SNAPEMPTY))
            ->addInt($currentTick)
            ->addInt($deltaTick);
    }
}
