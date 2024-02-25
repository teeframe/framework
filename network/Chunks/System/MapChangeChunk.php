<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class MapChangeChunk extends AbstractChunk
{
    public function __construct(public string $mapName, public int $crc, public int $size)
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::MAP_CHANGE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractInt(), $payload->extractInt());
    }

    public function getPayload(): array
    {
        return (new RawPayload())
            ->addString($this->mapName)
            ->addInt($this->crc)
            ->addInt($this->size)
            ->getPayload();
    }
}
