<?php

namespace Network\Decoder;

use Network\Enums\Network;

class DecodedPacket
{
    use Concerns\HasPacketDecoder;

    public function __construct(
        protected int $flags,
        protected int $ack,
        protected int $numChunks,
        protected array $rawPayload,
        protected array $chunks,
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

    /**
     * @return array<int, DecodedPacketChunk>
     */
    public function getChunks(): array
    {
        return $this->chunks;
    }

    public function getSize(): int
    {
        return count($this->rawPayload);
    }

    public function getControlMessage(): int
    {
        if (! ($this->flags & Network::PACKETFLAG_CONTROL)) {
            throw new \Exception('You can\'t get control message from non-control packet');
        }

        return $this->rawPayload[0];
    }

    public function getControlMessageExtra(): string
    {
        if (! ($this->flags & Network::PACKETFLAG_CONTROL)) {
            throw new \Exception('You can\'t get control message from non-control packet');
        }

        return implode('', array_map('chr', array_slice($this->rawPayload, 1)));
    }
}
