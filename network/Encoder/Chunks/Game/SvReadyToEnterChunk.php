<?php

namespace Network\Encoder\Chunks\Game;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SvReadyToEnterChunk extends ChunkEncoder
{
    public static function make(): static
    {
        return new static(Network::CHUNKFLAG_VITAL, Protocol::SV_READYTOENTER);
    }
}
