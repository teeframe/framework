<?php

namespace Network\Chunks\System;

use Network\Chunks\AbstractChunk;
use Network\NetworkMessages;
use Network\NetworkParams;
use Network\RawPayload;

class SnapSingleChunk extends AbstractChunk
{
    public function __construct(
        public int $currentTick,
        public int $deltaTick,
        public int $crc,
        public int $size,
        public array $snapPayload
    ) {
        parent::__construct(flags: 0, message: NetworkMessages::SNAPSINGLE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractBytes(NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE)
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->currentTick)
            ->addInt($this->deltaTick)
            ->addInt($this->crc)
            ->addInt($this->size)
            ->addBytes($this->snapPayload);
    }
}
