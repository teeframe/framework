<?php

namespace Network\Connection;

use Network\Chunks\AbstractChunk;
use Network\NetworkBase;
use Network\NetworkParams;
use Network\Packets\DefaultPacket;

class ChunkHandler
{
    /**
     * @var AbstractChunk[]
     */
    protected array $queue;

    /**
     * @var AbstractChunk[]
     */
    protected array $sentList;

    public function __construct(
        protected Connection $connection
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->sentList = [];
        $this->flushQueue();
    }

    public function add(AbstractChunk $chunk): static
    {
        if (($this->getQueueSize() + count($chunk->encode())) > NetworkParams::MAXIMUM_PACKET_SIZE || count($this->queue) === (NetworkParams::MAXIMUM_CHUNKS_PER_PACKET + 1)) {
            $this->send();
        }

        $this->queue[] = $chunk;

        if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->queue);

        $this->sentList = [...$this->sentList, ...$this->queue];

        $this->flushQueue();

        return $this->connection->sendPacket($packet);
    }

    public function resend(): bool
    {
        $resendChunks = array_map(fn (AbstractChunk $chunk): AbstractChunk => $chunk->addResendFlag(), $this->sentList);

        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $resendChunks, resend: true);

        return $this->connection->sendPacket($packet);
    }

    public function flushSentList(int $sequence): void
    {
        $this->sentList = array_filter($this->sentList, fn (AbstractChunk $chunk) => ! NetworkBase::isSequenceInBackroom($chunk->getSequence(), $sequence));
    }

    public function flushQueue(): void
    {
        $this->queue = [];
    }

    protected function getQueueSize(): int
    {
        $chunksPayload = [];

        foreach ($this->queue as $chunk) {
            $chunksPayload = [...$chunksPayload, ...$chunk->encode()];
        }

        return NetworkParams::PACKET_HEADER_SIZE_DEFAULT + count($chunksPayload);
    }
}
