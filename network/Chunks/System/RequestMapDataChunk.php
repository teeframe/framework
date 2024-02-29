<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;
use Network\NetworkMessages;
use Network\RawPayload;

class RequestMapDataChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::REQUEST_MAP_DATA, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static;
    }

    public function getPayload(): RawPayload
    {
        return new RawPayload;
    }
}
