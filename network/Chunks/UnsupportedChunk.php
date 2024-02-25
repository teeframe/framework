<?php

namespace Network\Chunks;

use Network\RawPayload;

class UnsupportedChunk extends AbstractChunk
{
    public function __construct(public int $unsupportedMessage)
    {
        parent::__construct(flags: 0, message: $unsupportedMessage);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractInt());
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addInt($this->message)
            ->getPayload();
    }
}
