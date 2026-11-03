<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClVoteChunk extends AbstractChunk
{
    public function __construct(public int $vote)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_VOTE);
    }

    public static function make(RawPayload $payload): self
    {
        return new self($payload->extractInt());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)->addInt($this->vote);
    }
}
