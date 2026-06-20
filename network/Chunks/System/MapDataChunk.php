<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class MapDataChunk extends AbstractChunk
{
    /**
     * @param int[] $data
     */
    public function __construct(
        public int $last,
        public int $crc,
        public int $chunk,
        public int $chunkSize,
        public array $data,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::MAP_DATA, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        $last      = $payload->extractInt();
        $crc       = $payload->extractInt();
        $chunk     = $payload->extractInt();
        $chunkSize = $payload->extractInt();
        $data      = $payload->extractBytes($chunkSize);

        return new static($last, $crc, $chunk, $chunkSize, $data);
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->last)
            ->addInt($this->crc)
            ->addInt($this->chunk)
            ->addInt($this->chunkSize)
            ->addBytes($this->data);
    }
}