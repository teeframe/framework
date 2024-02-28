<?php

namespace Network\Packets;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;

class DefaultPacket extends AbstractPacket
{
    /**
     * @param  array<int, AbstractChunk>  $chunks
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
     * @return array<int, AbstractChunk>
     */
    public function getChunks(): array
    {
        return $this->chunks;
    }
}
