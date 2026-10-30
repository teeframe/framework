<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Core\TickHandler;
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

    /** @var list<array{chunk: AbstractChunk, firstSendTime: int, lastSendTime: int}> */
    protected array $sentList;

    public function __construct(
        protected AbstractConnection $connection,
        protected TickHandler $tickHandler,
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

        if (($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL)
            && ! ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_RESEND)
        ) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

            $chunk->setSequence($this->connection->sequence);
        }

        return $this;
    }

    public function send(): bool
    {
        if ($this->queue === []) {
            return true;
        }

        $packet = new DefaultPacket(ack: $this->connection->ack, chunks: $this->queue);
        $now    = $this->tickHandler->getTime();

        foreach ($this->queue as $chunk) {
            if (($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL)
                && ! ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_RESEND)
            ) {
                $this->sentList[] = ['chunk' => clone $chunk, 'firstSendTime' => $now, 'lastSendTime' => $now];
            }
        }

        $this->queue     = [];
        $this->queueSize = NetworkParams::PACKET_HEADER_SIZE_DEFAULT;

        return $this->connection->sendPacket($packet);
    }

    /** Resend all pending vital chunks, or just the selected sequence. */
    public function resend(?int $sequence = null): bool
    {
        $now = $this->tickHandler->getTime();

        foreach ($this->sentList as $i => $entry) {
            if ($sequence !== null && $entry['chunk']->getSequence() !== $sequence) {
                continue;
            }

            // Use normal batching, retaining the original sequence and resend entry.
            $this->add((clone $entry['chunk'])->addResendFlag());
            $this->sentList[$i]['lastSendTime'] = $now;
        }

        return $this->send();
    }

    public function update(): ?string
    {
        $oldest = $this->sentList[0] ?? null;
        if ($oldest === null) {
            return null;
        }

        $now = $this->tickHandler->getTime();
        if ($now - $oldest['firstSendTime'] > 10_000_000_000) {
            return 'Too weak connection (not acked for 10 seconds)';
        }
        if ($now - $oldest['lastSendTime'] > 1_000_000_000) {
            $this->resend($oldest['chunk']->getSequence());
        }

        return null;
    }

    public function flushSentList(int $sequence): void
    {
        $this->sentList = array_values(array_filter(
            $this->sentList,
            fn (array $entry): bool => ! NetworkBase::isSequenceInBackroom($entry['chunk']->getSequence(), $sequence),
        ));
    }

    protected function getQueueSize(): int
    {
        return $this->queueSize;
    }
}
