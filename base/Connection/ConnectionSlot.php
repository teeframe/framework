<?php

namespace Base\Connection;

use Base\Server\ServerInstance;
use Network\Chunks\UnsupportedChunk;
use Network\Connection\Connection;
use Network\Enums\Network;
use Network\NetworkBase;
use Network\NetworkParams;
use Network\Packets\AbstractPacket;
use Network\Packets\ControlMessage;
use Network\Packets\DefaultPacket;

class ConnectionSlot extends Connection
{
    use Concerns\HasConsoleFeatures;

    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_READY      = 3;
    const STATE_INGAME     = 4;

    protected HandshakeHandler $handshakeHandler;

    public int $state;

    public int $lastSendTime;

    public int $lastRecvTime;

    public int $lastResendAskTime;

    public function __construct(protected int $slotIndex)
    {
        $this->handshakeHandler = new HandshakeHandler($this);

        parent::__construct();
    }

    public function reset(): void
    {
        parent::reset();

        $this->state             = static::STATE_EMPTY;
        $this->lastSendTime      = 0;
        $this->lastRecvTime      = 0;
        $this->lastResendAskTime = 0;
    }

    public function handshaker(): HandshakeHandler
    {
        return $this->handshakeHandler;
    }

    public function feedConnection(AbstractPacket $packet): bool
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
        if ($this->handshaker()->needsHandshake()) {
            if (! ($packet instanceof DefaultPacket)) {
                return false;
            }

            return $this->handshaker()->handleHandshake($packet);
        }

        // Handle online connection
        if ($packet->getFlags() & Network::PACKETFLAG_RESEND) {
            $this->chunks()->resend();
        }

        return ($packet instanceof ControlMessage)
            ? $this->handleControlMessagePacket($packet)
            : $this->handleDefaultPacket($packet);
    }

    public function closeConnection(string $reason): void
    {
        $this->sendControlMessage(Network::CTRLMSG_CLOSE, $reason);

        $this->reset();
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        return $this->sendPacket(new ControlMessage(message: $message, extra: $extra, ack: $this->ack));
    }

    public function sendResendKeepAliveMessage(): bool
    {
        return $this->sendPacket(new ControlMessage(message: Network::CTRLMSG_KEEPALIVE, ack: $this->ack, resend: true));
    }

    public function sendPacket(AbstractPacket $packet): bool
    {
        $this->lastSendTime = time();

        return ServerInstance::sendto($this->clientAddress, $this->clientPort, $packet->encodeToSend());
    }

    protected function handleControlMessagePacket(ControlMessage $packet): bool
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

    protected function handleDefaultPacket(DefaultPacket $packet): bool
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

    protected function updateConnectionState(AbstractPacket $packet): void
    {
        $this->lastRecvTime = time();
        $this->peerAck      = $packet->getAck();

        if (! ($packet instanceof DefaultPacket)) {
            return;
        }

        foreach ($packet->getChunks() as $chunk) {
            if ($chunk instanceof UnsupportedChunk) {
                $this->consoleInfo('Unsupported chunk received, game='.(int)$chunk->isGameMessage().' message='.$chunk->unsupportedMessage);
            }

            if (! ($chunk->getFlags() & Network::CHUNKFLAG_VITAL)) {
                continue;
            }

            $futureAck = ($this->ack + 1) % NetworkParams::MAXIMUM_ACK;

            if ($chunk->getSequence() === $futureAck) {
                $this->ack = $futureAck;
            } else {
                if (NetworkBase::isSequenceInBackroom($chunk->getSequence(), $this->ack)) {
                    continue;
                }

                if ($this->lastResendAskTime >= time() - 1) {
                    continue;
                }
    
                $this->consoleWarn("Out of sequence, asking for resend, {$chunk->getSequence()} - {$futureAck}");

                $this->sendResendKeepAliveMessage();

                $this->lastResendAskTime = time();
            }
        }
    }
}
