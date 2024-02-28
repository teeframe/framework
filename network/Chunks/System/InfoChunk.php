<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;
use Network\NetworkMessages;
use Network\RawPayload;

class InfoChunk extends AbstractChunk
{
    public function __construct(public string $version, public string $password = '')
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::INFO);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->version)
            ->addString($this->password);
    }
}
