<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;
use Network\NetworkMessages;
use Network\RawPayload;

class MapChangeChunk extends AbstractChunk
{
    public function __construct(public string $mapName, public int $crc, public int $size)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::MAP_CHANGE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractInt(), $payload->extractInt());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->mapName)
            ->addInt($this->crc)
            ->addInt($this->size);
    }
}
