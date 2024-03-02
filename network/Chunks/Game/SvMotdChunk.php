<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SvMotdChunk extends AbstractChunk
{
    public function __construct(public string $text)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::MAP_CHANGE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->text);
    }
}
