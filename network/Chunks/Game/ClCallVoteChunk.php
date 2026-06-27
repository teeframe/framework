<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClCallVoteChunk extends AbstractChunk
{
    public function __construct(
        public string $type,
        public string $value,
        public string $reason,
        public int $force = 0,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_CALLVOTE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractString(),
            $payload->extractString(),
            $payload->extractString(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->type)
            ->addString($this->value)
            ->addString($this->reason)
            ->addInt($this->force);
    }
}
