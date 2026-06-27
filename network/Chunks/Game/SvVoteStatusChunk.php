<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SvVoteStatusChunk extends AbstractChunk
{
    public function __construct(
        public int $yes,
        public int $no,
        public int $pass,
        public int $total,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_VOTESTATUS);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->yes)
            ->addInt($this->no)
            ->addInt($this->pass)
            ->addInt($this->total);
    }
}
