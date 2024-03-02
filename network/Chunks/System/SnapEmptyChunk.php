<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SnapEmptyChunk extends AbstractChunk
{
    public function __construct(
        public int $currentTick,
        public int $deltaTick
    ) {
        parent::__construct(flags: 0, message: NetworkMessages::SNAPEMPTY, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt()
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->currentTick)
            ->addInt($this->deltaTick);
    }
}
