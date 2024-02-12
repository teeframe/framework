<?php

namespace Network\Decoder;

use Enums\Network;

class DecodedPacket
{
    use Concerns\HasDecoder;

    public function __construct(
        protected int $flags,
        protected int $ack,
        protected int $numChunks,
        protected array $rawPayload,
        protected array $chunks,
        // protected int $dataSize,
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

    public function getControlMessage(): int
    {
        if (! ($this->flags & Network::PACKETFLAG_CONTROL)) {
            throw new \Exception('You can\'t get control message from non-control packet');
        }

        return array_slice($this->rawPayload, static::HEADER_SIZE_CONNLESS)[0];
    }
}
