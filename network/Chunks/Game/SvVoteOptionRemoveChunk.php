<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SvVoteOptionRemoveChunk extends AbstractChunk
{
    public function __construct(public string $description)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_VOTEOPTIONREMOVE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)->addString($this->description);
    }
}
