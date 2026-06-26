<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClEmoticonChunk extends AbstractChunk
{
    public function __construct(public int $emoticon)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_EMOTICON);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractInt());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->emoticon);
    }
}
