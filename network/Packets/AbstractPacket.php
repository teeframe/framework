<?php

namespace Network\Packets;

use Network\Enums\Network;
use Network\NetworkBase;

abstract class AbstractPacket
{
    protected int $numChunks = 0;

    public function __construct(
        protected int $flags,
        protected int $ack,
        protected array $payload
    ) {
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function getAck(): int
    {
        return $this->ack;
    }

    public function getNumChunks(): int
    {
        return $this->numChunks;
    }

    public function setNumChunks(int $amount): static
    {
        $this->numChunks = $amount;

        return $this;
    }

    public function getSize(): int
    {
        return count($this->payload);
    }

    public function encodeToSend(): string
    {
        return NetworkBase::packBuffer($this->encode());
    }

    protected function encode(): array
    {
        $encodedPayload = $this->payload;

        // if (!($this->flags & Network::PACKETFLAG_CONTROL)) {
        //     $this->flags |= Network::PACKETFLAG_COMPRESSION;

        //     $encodedPayload = NetworkBase::compressHuffman($encodedPayload);
        // }

        // TODO: Implement token support

        $header    = [];
        $header[0] = (($this->flags << 4) & 0xF0) | (($this->ack >> 8) & 0xF);
        $header[1] = $this->ack & 0xFF;
        $header[2] = $this->numChunks;

        return [...$header, ...$encodedPayload];
    }
}
