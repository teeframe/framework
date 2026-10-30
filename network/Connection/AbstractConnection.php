<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;

abstract class AbstractConnection
{
    public string $destinationAddress;

    public int $destinationPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    /** Monotonic transport timestamps, in nanoseconds. */
    public int $lastSendTime;

    public int $lastRecvTime;

    public int $lastResendAskTime;

    protected ChunkHandler $chunkHandler;

    protected SnapHandler $snapHandler;

    /** @var list<AbstractChunk> */
    protected array $receivedChunks = [];

    public function __construct(protected TickHandler $tickHandler)
    {
        $this->chunkHandler = new ChunkHandler($this, $tickHandler);
        $this->snapHandler  = new SnapHandler($this, $tickHandler);

        $this->reset();
    }

    public function reset(): void
    {
        $this->destinationAddress = '';
        $this->destinationPort    = 0;
        $this->sequence           = 0;
        $this->ack                = 0;
        $this->peerAck            = 0;
        $this->lastSendTime       = 0;
        $this->lastRecvTime       = 0;
        $this->lastResendAskTime  = 0;
        $this->receivedChunks     = [];

        $this->chunks()->reset();
        $this->snaps()->reset();
    }

    public function init(string $destinationAddress, int $destinationPort): void
    {
        $this->destinationAddress = $destinationAddress;
        $this->destinationPort    = $destinationPort;
        $now                      = $this->tickHandler->getTime();
        $this->lastSendTime       = $now;
        $this->lastRecvTime       = $now;
        $this->lastResendAskTime  = $now - 1_000_000_000;
    }

    abstract protected function handlePacketSending(AbstractPacket $packet): bool;

    abstract protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void;

    abstract protected function handleConnectionOutOfSequence(int $sequence, int $ack): void;

    public function chunks(): ChunkHandler
    {
        return $this->chunkHandler;
    }

    public function snaps(): SnapHandler
    {
        return $this->snapHandler;
    }

    /** @return list<AbstractChunk> */
    public function getReceivedChunks(): array
    {
        return $this->receivedChunks;
    }

    public function update(): ?string
    {
        $now = $this->tickHandler->getTime();
        if ($now - $this->lastRecvTime > 10_000_000_000) {
            return 'Timeout';
        }

        if (($reason = $this->chunks()->update()) !== null) {
            return $reason;
        }

        if ($now - $this->lastSendTime > 500_000_000) {
            $this->chunks()->send();
        }
        if ($now - $this->lastSendTime > 1_000_000_000) {
            $this->sendControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE);
        }

        return null;
    }

    public function sendPacket(AbstractPacket $packet): bool
    {
        $this->lastSendTime = $this->tickHandler->getTime();

        return $this->handlePacketSending($packet);
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        return $this->sendPacket(new ControlMessage(message: $message, extra: $extra, ack: $this->ack));
    }

    protected function validateFeeding(AbstractPacket $packet): bool
    {
        $this->receivedChunks = [];

        if ($this->sequence >= $this->peerAck) {
            if ($packet->getAck() < $this->peerAck || $packet->getAck() > $this->sequence) {
                return false;
            }
        } else {
            if ($packet->getAck() < $this->peerAck && $packet->getAck() > $this->sequence) {
                return false;
            }
        }

        $this->updateConnectionState($packet);

        return true;
    }

    protected function updateConnectionState(AbstractPacket $packet): void
    {
        $this->lastRecvTime = $this->tickHandler->getTime();
        $this->peerAck      = $packet->getAck();

        $this->chunks()->flushSentList($this->peerAck);

        if (! ($packet instanceof DefaultPacket)) {
            return;
        }

        foreach ($packet->getChunks() as $chunk) {
            if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
                $futureAck = ($this->ack + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

                if ($chunk->getSequence() === $futureAck) {
                    $this->ack = $futureAck;
                } else {
                    if (! NetworkBase::isSequenceInBackroom($chunk->getSequence(), $this->ack)
                        && $this->lastRecvTime - $this->lastResendAskTime >= 1_000_000_000
                    ) {
                        $this->lastResendAskTime = $this->lastRecvTime;
                        $this->handleConnectionOutOfSequence($chunk->getSequence(), $futureAck);
                    }

                    continue;
                }
            }

            if ($chunk instanceof UnsupportedChunk) {
                $this->handleUnsupportedChunk($chunk);
            }

            $this->receivedChunks[] = $chunk;
        }
    }
}
