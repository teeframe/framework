<?php

namespace Base\Connection;

use Base\Server\ServerInstance;
use Base\SnapInterface;
use Network\Chunks\System\InputChunk;
use Network\Chunks\System\InputTimingChunk;
use Network\Chunks\UnsupportedChunk;
use Network\Connection\Connection;
use Network\Enums\Network;
use Network\NetworkBase;
use Network\NetworkParams;
use Network\Packets\AbstractPacket;
use Network\Packets\ControlMessage;
use Network\Packets\DefaultPacket;
use Network\SnapItems\ObjClientInfoItem;
use Network\SnapItems\ObjPlayerInfoItem;

class ConnectionSlot extends Connection implements SnapInterface
{
    use Concerns\HasConsole;
    use Concerns\HasClientData;

    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_READY      = 3;
    const STATE_INGAME     = 4;

    protected HandshakeHandler $handshakeHandler;

    public int $state;

    public function __construct(protected int $slotIndex)
    {
        $this->handshakeHandler = new HandshakeHandler($this);

        parent::__construct();
    }

    public function reset(): void
    {
        parent::reset();

        $this->resetClientData();

        $this->state = static::STATE_EMPTY;
    }

    public function handshaker(): HandshakeHandler
    {
        return $this->handshakeHandler;
    }

    public function feedConnection(AbstractPacket $packet): bool
    {
        if (! $this->validateFeeding($packet)) {
            $this->consoleError('Invalid ack');
            
            return false;
        } 

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

            if ($chunk instanceof InputChunk) {
                $this->snaps()->setLastAckedTick($chunk->ackGameTick);

                $this->chunks()->add(new InputTimingChunk(
                    intendedTick: $chunk->predictionTick, 
                    timeLeft: ($chunk->predictionTick - ServerInstance::context()->getCurrentTick()) / NetworkParams::TICKS_PER_SECOND * 1000, 
                ));

                // TODO: Implement NETMSG_INPUT
            }

            

            // TODO: Implement NETMSG_PING

            // TODO: Implement NETMSG_RCON_CMD

            // TODO: Implement NETMSG_RCON_AUTH
        }

        return true;
    }

    public function doSnap(int $indexAsking): array
    {
        return [
            // new ObjClientInfoItem(
            //     name: $this->name,
            //     clan: $this->clan,
            //     country: $this->country,
            //     skinName: $this->skinName,
            //     useCustomColor: $this->useCustomColor,
            //     colorBody: $this->colorBody,
            //     colorFoot: $this->colorFeet,
            // ),
            new ObjPlayerInfoItem(
                local: $this->slotIndex === $indexAsking,
                clientId: $this->slotIndex,
                team: 0,
                score: 0,
                latency: $this->snaps()->getLatency(),
            ),
        ];
    }

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        return ServerInstance::sendto($this->destinationAddress, $this->destinationPort, $packet->encodeToSend());
    }

    protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void
    {
        $this->consoleWarn('Unsupported chunk received, game='.(int)$chunk->isGameMessage().' message='.$chunk->unsupportedMessage);
    }

    protected function handleConnectionOutOfSequence(int $sequence, int $ack): void
    {
        $this->consoleWarn("Out of sequence, asking for resend, {$sequence} - {$ack}");

        $this->sendPacket(new ControlMessage(message: Network::CTRLMSG_KEEPALIVE, ack: $this->ack, resend: true));
    }
}
