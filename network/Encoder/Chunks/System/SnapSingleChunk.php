<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Protocol;

class SnapSingleChunk extends ChunkEncoder
{
    public static function make(int $currentTick, int $deltaTick, int $crc, int $size, array $payload): static
    {
        return (new static(0, Protocol::SNAPSINGLE))
            ->addInt($currentTick)
            ->addInt($deltaTick)
            ->addInt($crc)
            ->addInt($size)
            ->addBytes($payload);
    }
}
