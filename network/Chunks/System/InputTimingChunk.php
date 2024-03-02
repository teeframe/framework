<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class InputTimingChunk extends AbstractChunk
{
    public function __construct(
        public int $intendedTick,
        public int $timeLeft,
    ) {
        parent::__construct(flags: 0, message: NetworkMessages::INPUTTIMING, isSystem: true);
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
            ->addInt($this->intendedTick)
            ->addInt($this->timeLeft);
    }
}
