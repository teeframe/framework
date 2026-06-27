<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClKillChunk extends AbstractChunk
{
    public function __construct()
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_KILL);
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
