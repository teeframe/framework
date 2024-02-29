<?php

namespace Network\Chunks;

use Network\RawPayload;

class UnsupportedChunk extends AbstractChunk
{
    public function __construct(public int $unsupportedMessage, int $flags, bool $isSystem)
    {
        parent::__construct(flags: $flags, message: $unsupportedMessage, isSystem: $isSystem);
    }

    public function getPayload(): RawPayload
    {
        return new RawPayload;
    }
}
