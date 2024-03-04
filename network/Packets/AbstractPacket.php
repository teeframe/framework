<?php

namespace TeeFrame\Network\Packets;

use TeeFrame\Network\NetworkBase;

abstract class AbstractPacket
{
    protected int $numChunks = 0;

    /**
     * @param int[] $payload
     */
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

    public function isResend(): bool
    {
        return (bool) ($this->flags & NetworkBase::PACKET_FLAG_RESEND);
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

    /**
     * @return int[]
     */
    protected function encode(): array
    {
        $encodedPayload = $this->payload;

        // if (!($this->flags & NetworkBase::PACKET_FLAG_TYPE_CONTROL)) {
        //     $this->flags |= NetworkBase::PACKET_FLAG_COMPRESSION;

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
