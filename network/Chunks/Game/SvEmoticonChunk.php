<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SvEmoticonChunk extends AbstractChunk
{
    public function __construct(
        public int $clientId,
        public int $emoticon,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_EMOTICON);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->clientId)
            ->addInt($this->emoticon);
    }
}
