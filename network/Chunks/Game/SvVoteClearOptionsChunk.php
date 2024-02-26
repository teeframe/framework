<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class SvVoteClearOptionsChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::SV_VOTECLEAROPTIONS);
    }

    public static function make(RawPayload $payload): static
    {
        return new static;
    }

    public function getPayload(): RawPayload
    {
        return new RawPayload;
    }
}
