<?php

namespace Network\Connection;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\NetworkParams;
use Network\NetworkBase;
use Network\Packets\DefaultPacket;

class ChunkHandler
{
    /**
     * @var array<int, AbstractChunk>
     */
    protected array $queue = [];

    /**
     * @var array<int, AbstractChunk>
     */
    protected array $sentList = [];

    public function __construct(
        protected Connection $connection
    ) {
    }

    public function add(AbstractChunk $chunk): static
    {
        $this->queue[] = $chunk;

        // TODO: Add sending if reach the limit

        if ($chunk->getFlags() & Network::CHUNKFLAG_VITAL) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->queue);

        $this->flushQueue();

        return $this->connection->sendPacket($packet);
    }

    public function resend(): bool
    {
        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->sentList, resend: true); // TODO: Apply resend flag to all sent chunks

        return $this->connection->sendPacket($packet);
    }

    public function flushSentList(int $ack = -1): void
    {
        if ($ack === -1) {
            $this->sentList = [];

            return;
        }

        $this->sentList = array_filter($this->sentList, fn(AbstractChunk $chunk) => ! NetworkBase::isSequenceInBackroom($chunk->getSequence(), $ack));
    }

    public function flushQueue(): void
    {
        $this->sentList = [...$this->sentList, ...$this->queue];

        $this->queue = [];
    }
}
