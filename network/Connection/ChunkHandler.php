<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\DefaultPacket;

class ChunkHandler
{
    /**
     * @var AbstractChunk[]
     */
    protected array $queue;

    protected int $queueSize;

    /**
     * @var AbstractChunk[]
     */
    protected array $sentList;

    public function __construct(
        protected AbstractConnection $connection
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->sentList  = [];
        $this->queue     = [];
        $this->queueSize = NetworkParams::PACKET_HEADER_SIZE_DEFAULT;
    }

    public function add(AbstractChunk $chunk): static
    {
        $chunkSize = count($chunk->encode());
        if ($chunkSize + NetworkParams::PACKET_HEADER_SIZE_DEFAULT > NetworkParams::MAXIMUM_PACKET_SIZE) {
            throw new \RuntimeException('Chunk exceeds the packet size limit');
        }

        if ($this->getQueueSize() + $chunkSize > NetworkParams::MAXIMUM_PACKET_SIZE
            || count($this->queue) >= NetworkParams::MAXIMUM_CHUNKS_PER_PACKET
        ) {
            $this->send();
        }

        $this->queue[] = $chunk;
        $this->queueSize += $chunkSize;

        if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->queue);

        $this->sentList  = [...$this->sentList, ...$this->queue];
        $this->queue     = [];
        $this->queueSize = NetworkParams::PACKET_HEADER_SIZE_DEFAULT;

        return $this->connection->sendPacket($packet);
    }

    public function resend(): bool
    {
        // TODO: Implement code to check MAXIMUM_PACKET_SIZE and MAXIMUM_CHUNKS_PER_PACKET

        $resendChunks = array_map(fn (AbstractChunk $chunk): AbstractChunk => $chunk->addResendFlag(), $this->sentList);

        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $resendChunks, resend: true);

        return $this->connection->sendPacket($packet);
    }

    public function flushSentList(int $sequence): void
    {
        $this->sentList = array_filter($this->sentList, fn (AbstractChunk $chunk) => ! NetworkBase::isSequenceInBackroom($chunk->getSequence(), $sequence));
    }

    protected function getQueueSize(): int
    {
        return $this->queueSize;
    }
}
