<?php

namespace Network\Connection;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\NetworkBase;
use Network\NetworkParams;
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

        if (($this->getQueueSize() + count($chunk->encode())) > NetworkParams::MAXIMUM_PACKET_SIZE) {
            $this->send();
        }

        if ($chunk->getFlags() & Network::CHUNKFLAG_VITAL) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->queue);

        $this->addToSentList($this->queue);
        $this->flushQueue();

        return $this->connection->sendPacket($packet);
    }

    public function resend(): bool
    {
        $resendChunks = array_map(fn (AbstractChunk $chunk): AbstractChunk => $chunk->addResendFlag(), $this->sentList);

        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $resendChunks, resend: true);

        return $this->connection->sendPacket($packet);
    }

    /**
     * @param  array<int, AbstractChunk>  $chunks
     */
    public function addToSentList(array $chunks): void
    {
        $this->sentList = [...$this->sentList, ...$chunks];
    }

    public function flushSentList(int $ack = -1): void
    {
        if ($ack === -1) {
            $this->sentList = [];

            return;
        }

        $this->sentList = array_filter($this->sentList, fn (AbstractChunk $chunk) => ! NetworkBase::isSequenceInBackroom($chunk->getSequence(), $ack));
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
