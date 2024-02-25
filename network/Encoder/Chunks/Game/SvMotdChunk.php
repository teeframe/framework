<?php

namespace Network\Encoder\Chunks\Game;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SvMotdChunk extends ChunkEncoder
{
    public static function make(string $message): static
    {
        return (new static(Network::CHUNKFLAG_VITAL, Protocol::SV_MOTD))
            ->addString($message);
    }
}
