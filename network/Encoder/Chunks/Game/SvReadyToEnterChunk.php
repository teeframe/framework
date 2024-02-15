<?php

namespace Network\Encoder\Chunks\Game;

use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SvReadyToEnterChunk extends PackageChunkEncoder
{
    public static function make(): static
    {
        return new static(Network::CHUNKFLAG_VITAL, Protocol::SV_READYTOENTER);
    }
}
