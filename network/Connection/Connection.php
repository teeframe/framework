<?php

namespace Network\Connection;

use Network\Chunks\UnsupportedChunk;
use Network\NetworkBase;
use Network\NetworkParams;
use Network\Packets\AbstractPacket;
use Network\Packets\ControlMessage;
use Network\Packets\DefaultPacket;

abstract class Connection
{
    public string $destinationAddress;

    public int $destinationPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    public int $lastSendTime;

    public int $lastRecvTime;

    public int $lastResendAskTime;

    protected ChunkHandler $chunkHandler;

    protected SnapHandler $snapHandler;

    public function __construct()
    {
        $this->chunkHandler = new ChunkHandler($this);
        $this->snapHandler  = new SnapHandler($this);

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

        $this->chunks()->reset();
        $this->snaps()->reset();
    }

    public function init(string $destinationAddress, int $destinationPort): void
    {
        $this->destinationAddress = $destinationAddress;
        $this->destinationPort    = $destinationPort;
        $this->lastSendTime       = time();
        $this->lastRecvTime       = time();
        $this->lastResendAskTime  = time();
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

    public function sendPacket(AbstractPacket $packet): bool
    {
        $this->lastSendTime = time();

        return $this->handlePacketSending($packet);
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        return $this->sendPacket(new ControlMessage(message: $message, extra: $extra, ack: $this->ack));
    }

    protected function validateFeeding(AbstractPacket $packet): bool
    {
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
        $this->lastRecvTime = time();
        $this->peerAck      = $packet->getAck();

        if (! ($packet instanceof DefaultPacket)) {
            return;
        }

        foreach ($packet->getChunks() as $chunk) {
            if ($chunk instanceof UnsupportedChunk) {
                $this->handleUnsupportedChunk($chunk);
            }

            if (! ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL)) {
                continue;
            }

            $futureAck = ($this->ack + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;

            if ($chunk->getSequence() === $futureAck) {
                $this->ack = $futureAck;

                $this->chunks()->flushSentList($this->ack);

                continue;
            }

            // Out of Sequence
            if (NetworkBase::isSequenceInBackroom($chunk->getSequence(), $this->ack) || $this->lastResendAskTime >= time() - 1) {
                continue;
            }

            $this->lastResendAskTime = time();

            $this->handleConnectionOutOfSequence($chunk->getSequence(), $futureAck);
        }
    }
}
