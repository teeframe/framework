<?php

namespace Base\Connection;

use Network\Connection\Connection;
use Network\Decoder\DecodedPacket;
use Network\Encoder\PacketEncoder;
use Network\Enums\Network;
use Network\Limits;
use Network\NetworkBase;

class ConnectionSlot extends Connection
{
    use Concerns\HasConnectionHandshake;
    use Concerns\HasConsoleFeatures;

    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_READY      = 3;
    const STATE_INGAME     = 4;

    public int $state;

    public int $lastSendTime;

    public int $lastRecvTime;

    public function __construct(protected int $slotIndex)
    {
        parent::__construct();
    }

    public function reset(): void
    {
        parent::reset();

        $this->state          = static::STATE_EMPTY;
        $this->lastSendTime   = 0;
        $this->lastRecvTime   = 0;
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

        $this->updateConnectionState($packet);

        // Handle connection handshake
        if ($this->isConnectionOnHandshake()) {
            return $this->handleConnectionHandshake($packet);
        }

        // Handle online connection
        if ($packet->getFlags() & Network::PACKETFLAG_RESEND) {
            $this->chunks()->resend();
        }

        return ($packet->getFlags() & Network::PACKETFLAG_CONTROL)
            ? $this->handleControlMessagePacket($packet)
            : $this->handleDefaultPacket($packet);
    }

    public function closeConnection(string $reason): void
    {
        $this->sendControlMessage(Network::CTRLMSG_CLOSE, $reason);

        $this->reset();
    }

    protected function handleControlMessagePacket(DecodedPacket $packet): bool
    {
        $message = $packet->getControlMessage();

        if ($message === Network::CTRLMSG_CLOSE) {
            $this->consoleInfo('Closed reason='.$packet->getControlMessageExtra());

            $this->reset();
        }

        // CTRLMSG_KEEPALIVE is used just to keep the connection alive
        // by updating the lastRecvTime, since updateConnectionState()
        // already do this, we don't need to do anything here

        return true;
    }

    protected function handleDefaultPacket(DecodedPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            if ($chunk->isGameMessage()) {
                // TODO: Implement GameServer()->OnMessage
            }

            // TODO: Implement NETMSG_INPUT

            // TODO: Implement NETMSG_PING

            // TODO: Implement NETMSG_RCON_CMD

            // TODO: Implement NETMSG_RCON_AUTH
        }

        return true;
    }

    protected function updateConnectionState(DecodedPacket $packet): void
    {
        $this->lastRecvTime = time();

        if ($packet->getFlags() & Network::PACKETFLAG_CONTROL) {
            return;
        }

        $this->peerAck = $packet->getAck();

        foreach ($packet->getChunks() as $chunk) {
            if (! ($chunk->getFlags() & Network::CHUNKFLAG_VITAL)) {
                continue;
            }

            $futureAck = ($this->ack + 1) % Limits::MAXIMUM_ACK;

            if ($chunk->getSequence() === $futureAck) {
                $this->ack = $futureAck;
            } else {
                if (NetworkBase::isSequenceInBackroom($chunk->getSequence(), $this->ack)) {
                    continue;
                }

                $this->consoleWarn("Out of sequence, asking for resend, {$chunk->getSequence()} - {$futureAck}");

                $this->sendResendKeepAliveMessage();
            }
        }
    }

    protected function sendResendKeepAliveMessage(): bool
    {
        $encoder = PacketEncoder::makeResendKeepAliveMessage($this->ack);
        
        return $encoder->send($this->clientAddress, $this->clientPort);
    }

    protected function sendControlMessage(int $message, string $extra = ''): bool
    {
        $encoder = PacketEncoder::makeControlMessage($message, $extra, $this->ack);

        return $encoder->send($this->clientAddress, $this->clientPort);
    }
}
