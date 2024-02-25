<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class InfoChunk extends AbstractChunk
{
    public function __construct(public string $version, public string $password = '')
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::INFO);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractString());
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addString($this->version)
            ->addString($this->password)
            ->getPayload();
    }
}
