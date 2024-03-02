<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class MapChangeChunk extends AbstractChunk
{
    public function __construct(public string $mapName, public int $crc, public int $size)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::MAP_CHANGE, isSystem: true);
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
