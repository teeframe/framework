<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Protocol;

class SnapEmptyChunk extends ChunkEncoder
{
    public static function make(int $currentTick, int $deltaTick): static
    {
        return (new static(0, Protocol::SNAPEMPTY))
            ->addInt($currentTick)
            ->addInt($deltaTick);
    }
}
