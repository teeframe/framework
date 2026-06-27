<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClSetSpectatorModeChunk extends AbstractChunk
{
    public function __construct(public int $spectatorId)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_SET_SPECTATOR_MODE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractInt());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)->addInt($this->spectatorId);
    }
}
