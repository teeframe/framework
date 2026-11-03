<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RequestMapDataChunk extends AbstractChunk
{
    public function __construct(
        public int $chunk = 0,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::REQUEST_MAP_DATA, isSystem: true);
    }

    public static function make(RawPayload $payload): self
    {
        return new self($payload->extractInt());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->chunk);
    }
}
