<?php

namespace Network\Encoder\Chunks\Game;

use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SvMotdChunk extends PackageChunkEncoder
{
    public static function make(string $message): static
    {
        return (new static(Network::CHUNKFLAG_VITAL, Protocol::SV_MOTD))
            ->addString($message);
    }
}
