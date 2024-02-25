<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class EnterGameChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::ENTERGAME);
    }

    public static function make(RawPayload $payload): static
    {
        return new static;
    }

    public function getPayload(): array
    {
        return [];
    }
}
