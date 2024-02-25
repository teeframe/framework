<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class SvReadyToEnterChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::SV_READYTOENTER);
    }

    public static function make(RawPayload $payload): static
    {
        return new static();
    }

    public function getPayload(): array
    {
        return (new RawPayload())->getPayload();
    }
}
