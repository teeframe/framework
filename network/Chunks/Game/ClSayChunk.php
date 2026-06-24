<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class ClSayChunk extends AbstractChunk
{
    public function __construct(
        public bool $team,
        public string $text,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_SAY);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractBool(),
            $payload->extractString(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addBool($this->team)
            ->addString($this->text);
    }
}