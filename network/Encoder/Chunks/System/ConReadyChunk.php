<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class ConReadyChunk extends ChunkEncoder
{
    public static function make(): static
    {
        return new static(Network::CHUNKFLAG_VITAL, Protocol::CON_READY);
    }
}
