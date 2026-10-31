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

    /** @var array<int, int> Encoded sizes of queued and sent vital chunks, keyed by sequence. */
    protected array $pendingSizes;
    protected int $pendingSize;
    protected bool $bufferFull;

    public function __construct(
        protected AbstractConnection $connection,
        protected TickHandler $tickHandler,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->sentList     = [];
        $this->queue        = [];
        $this->queueSize    = NetworkParams::PACKET_HEADER_SIZE_DEFAULT;
        $this->pendingSizes = [];
        $this->pendingSize  = 0;
        $this->bufferFull   = false;
    }

    public function add(AbstractChunk $chunk): static
    {
        if ($this->bufferFull) {
            return $this;
        }

        $chunkSize = count($chunk->encode());
        if ($chunkSize + NetworkParams::PACKET_HEADER_SIZE_DEFAULT > NetworkParams::MAXIMUM_PACKET_SIZE) {
            throw new \RuntimeException('Chunk exceeds the packet size limit');
        }

        $newVital = ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL)
            && ! ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_RESEND);

        // Reserve queued data too, before flushing or allocating a sequence.
        // Staying below half the sequence space keeps old ACKs unambiguous.
        if ($newVital && ($this->pendingSize + $chunkSize > NetworkParams::MAXIMUM_RESEND_BUFFER_SIZE
            || count($this->pendingSizes) >= intdiv(NetworkParams::MAXIMUM_ACK_NUMBER, 2) - 1)) {
            $this->bufferFull = true;

            return $this;
        }

        if ($this->getQueueSize() + $chunkSize > NetworkParams::MAXIMUM_PACKET_SIZE
            || count($this->queue) >= NetworkParams::MAXIMUM_CHUNKS_PER_PACKET
        ) {
            $this->send();
        }

        $this->queue[] = $chunk;
        $this->queueSize += $chunkSize;

        if ($newVital) {
            $this->connection->sequence = ($this->connection->sequence + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

            $chunk->setSequence($this->connection->sequence);
            $this->pendingSizes[$this->connection->sequence] = $chunkSize;
            $this->pendingSize += $chunkSize;
        }

        return $this;
    }

    public function send(): bool
    {
        if ($this->bufferFull) {
            return false;
        }

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
        if ($this->bufferFull) {
            return false;
        }

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
        if ($this->bufferFull) {
            return 'Too weak connection (out of buffer)';
        }

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
        $pending = [];
        foreach ($this->sentList as $entry) {
            $chunkSequence = $entry['chunk']->getSequence();
            if (NetworkBase::isSequenceInBackroom($chunkSequence, $sequence)) {
                $this->pendingSize -= $this->pendingSizes[$chunkSequence];
                unset($this->pendingSizes[$chunkSequence]);
            } else {
                $pending[] = $entry;
            }
        }
        $this->sentList = $pending;
    }

    protected function getQueueSize(): int
    {
        return $this->queueSize;
    }
}
