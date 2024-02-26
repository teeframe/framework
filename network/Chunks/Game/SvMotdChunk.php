<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class SvMotdChunk extends AbstractChunk
{
    public function __construct(public string $text)
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::MAP_CHANGE);
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
