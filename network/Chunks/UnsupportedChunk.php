<?php

namespace TeeFrame\Network\Chunks;

use TeeFrame\Network\RawPayload;

class UnsupportedChunk extends AbstractChunk
{
    public function __construct(public int $unsupportedMessage, int $flags, bool $isSystem)
    {
        parent::__construct(flags: $flags, message: $unsupportedMessage, isSystem: $isSystem);
    }

    public static function make(RawPayload $payload): static
    {
        throw new \RuntimeException('You cannot instantiate an unsupported chunk from the make method.');
    }

    public function getPayload(): RawPayload
    {
        return new RawPayload;
    }
}
