<?php

namespace TeeFrame\Network\Packets;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;

class DefaultPacket extends AbstractPacket
{
    /**
     * @param  AbstractChunk[]  $chunks
     */
    public function __construct(protected array $chunks, int $ack, bool $resend = false)
    {
        $flags = NetworkBase::PACKET_FLAG_TYPE_DEFAULT;

        if ($resend) {
            $flags |= NetworkBase::PACKET_FLAG_RESEND;
        }

        $payload = [];
        foreach ($chunks as $chunk) {
            $payload = [...$payload, ...$chunk->encode()];
        }

        parent::__construct(flags: $flags, ack: $ack, payload: $payload);

        $this->setNumChunks(count($chunks));
    }

    /**
     * @return AbstractChunk[]
     */
    public function getChunks(): array
    {
        return $this->chunks;
    }
}
