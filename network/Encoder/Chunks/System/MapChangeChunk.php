<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class MapChangeChunk extends ChunkEncoder
{
    public static function make(string $mapName, int $crc, int $size): static
    {
        return (new static(Network::CHUNKFLAG_VITAL, Protocol::MAP_CHANGE))
            ->addString($mapName)
            ->addInt($crc)
            ->addInt($size);
    }
}
