<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;
use Network\NetworkMessages;
use Network\RawPayload;

class SvVoteClearOptionsChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_VOTECLEAROPTIONS);
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
