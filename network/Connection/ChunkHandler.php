<?php

namespace Network\Connection;

use Network\Encoder\ChunkEncoder;
use Network\Encoder\PacketEncoder;
use Network\Enums\Network;
use Network\Limits;

class ChunkHandler
{
    /**
     * @var array<int, ChunkEncoder>
     */
    protected array $queue = [];

    /**
     * @var array<int, ChunkEncoder>
     */
    protected array $sentList = [];

    public function __construct(
        protected Connection $connection
    ) {
    }

    public function add(ChunkEncoder $chunk): static
    {
        $this->queue[] = $chunk;

        if ($chunk->getFlags() & Network::CHUNKFLAG_VITAL) {
            $this->connection->sequence = ($this->connection->sequence + 1) % Limits::MAXIMUM_ACK;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        $encoder = new PacketEncoder(0, $this->connection->ack, $this->queue);

        $this->flushQueue();

        return $encoder->send($this->connection->clientAddress, $this->connection->clientPort);
    }

    public function resend(): bool
    {
        $encoder = new PacketEncoder(Network::PACKETFLAG_RESEND, $this->connection->ack, $this->sentList);

        return $encoder->send($this->connection->clientAddress, $this->connection->clientPort);
    }

    public function flushSentList(int $ack): void
    {
        $this->sentList = array_filter($this->sentList, fn(ChunkEncoder $chunk) => $chunk->getSequence() > $ack);
    }

    public function flushQueue(): void
    {
        $this->sentList = [...$this->sentList, ...$this->queue];

        $this->queue = [];
    }
}
