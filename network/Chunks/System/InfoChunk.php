<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class InfoChunk extends AbstractChunk
{
    public function __construct(public string $version, public string $password = '')
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::INFO, isSystem: true);
    }

    public static function make(RawPayload $payload): self
    {
        return new self($payload->extractString(), $payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->version)
            ->addString($this->password);
    }
}
