<?php

namespace Network\Chunks;

class UnsupportedChunk extends AbstractChunk
{
    public function __construct(public int $flags, public int $unsupportedMessage)
    {
        parent::__construct(flags: $flags, message: $unsupportedMessage);
    }

    public function getPayload(): array
    {
        return [];
    }
}
