<?php

namespace Network\Packets;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;

class DefaultPacket extends AbstractPacket
{
     /**
     * @param  array<int, AbstractChunk>  $chunks
     */
    public function __construct(int $ack, array $chunks, bool $resend = false)
    {
        $flags = 0;

        if ($resend) {
            $flags |= Network::PACKETFLAG_RESEND;
        }

        $payload = [];
        foreach ($chunks as $chunk) {
            $payload = [...$payload, ...$chunk->encode()];
        }

        parent::__construct(flags: $flags, ack: $ack, payload: $payload);

        $this->setNumChunks(count($chunks));
    }
}