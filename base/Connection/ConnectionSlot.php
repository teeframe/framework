<?php

namespace Base\Connection;

use Network\Decoder\DecodedPacket;
use Network\Enums\Network;

class ConnectionSlot
{
    use Concerns\HasConnectionHandshake;
    use Concerns\HasConsoleFeatures;
    use Concerns\HasPacketFeatures;

    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_READY      = 3;
    const STATE_INGAME     = 4;
    const STATE_ERROR      = 5;

    public string $clientAddress;

    public int $clientPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    public bool $remoteClosed;

    public int $state;

    public int $lastSendTime;

    public int $lastRecvTime;

    public int $lastUpdateTime;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->state          = static::STATE_EMPTY;
        $this->clientAddress  = '';
        $this->clientPort     = 0;
        $this->sequence       = 0;
        $this->ack            = 0;
        $this->peerAck        = 0;
        $this->remoteClosed   = false;
        $this->lastSendTime   = 0;
        $this->lastRecvTime   = 0;
        $this->lastUpdateTime = 0;

        $this->resetChunksQueue();
    }

    public function feedConnection(DecodedPacket $packet): bool
    {
        if ($this->sequence >= $this->peerAck) {
            if ($packet->getAck() < $this->peerAck || $packet->getAck() > $this->sequence) {
                $this->consoleError('Invalid ack');

                return false;
            }
        } else {
            if ($packet->getAck() < $this->peerAck && $packet->getAck() > $this->sequence) {
                $this->consoleError('Invalid ack');

                return false;
            }
        }

        $this->updateConnection($packet);

        // Handle connection handshake
        if ($this->isConnectionOnHandshake()) {
            return $this->handleHandshakeConnection($packet);
        }

        // Handle online connection
        if ($packet->getFlags() & Network::PACKETFLAG_RESEND) {
            return $this->handleResendPacket($packet);
        }
        if ($packet->getFlags() & Network::PACKETFLAG_CONTROL) {
            return $this->handleControlMessagePacket($packet);
        }

        return $this->handleDefaultPacket($packet);
    }

    public function updateConnection(DecodedPacket $packet): void
    {
        $this->lastRecvTime = time();
        $this->peerAck      = $packet->getAck();

        // EQUIVALENT - handle sequence stuff
        foreach ($packet->getChunks() as $chunk) {
            if (! ($chunk->getFlags() & Network::CHUNKFLAG_VITAL)) {
                continue;
            }

            if ($chunk->getSequence() === ($this->ack + 1)) {
                $this->ack++; // TODO: The maximum ack is needed here?
            } else {
                // TODO: Implement chunk resending
            }
        }
    }

    public function handleResendPacket(DecodedPacket $packet): bool
    {
        // TODO: Implement CNetConnection::Resend()

        return true;
    }

    public function handleControlMessagePacket(DecodedPacket $packet): bool
    {
        $message = $packet->getControlMessage();

        if ($message === Network::CTRLMSG_KEEPALIVE) {
            return true;
        }
        if ($message === Network::CTRLMSG_CLOSE) {
            $this->consoleInfo('Closed reason='.$packet->getControlMessageExtra());

            $this->remoteClosed = true;
            $this->state        = static::STATE_EMPTY;

            return true;
        }

        return false;
    }

    public function handleDefaultPacket(DecodedPacket $packet): bool
    {
        // TODO: Implement this

        return true;
    }
}
