<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Protocol;

class SnapChunk extends ChunkEncoder
{
    public static function make(int $currentTick, int $deltaTick, int $totalNumber, int $currentNumber, int $crc, int $size, array $payload): static
    {
        return (new static(0, Protocol::SNAP))
            ->addInt($currentTick)
            ->addInt($deltaTick)
            ->addInt($totalNumber)
            ->addInt($currentNumber)
            ->addInt($crc)
            ->addInt($size)
            ->addBytes($payload);
    }
}
