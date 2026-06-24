<?php

namespace TeeFrame\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\InputTimingChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionSlot extends AbstractConnection
{
    use Concerns\HasConnectionConsole;

    const STATE_WAITING_INIT = 0;
    const STATE_CONNECTING   = 1;
    const STATE_LOADING      = 2;
    const STATE_READY        = 3;
    const STATE_INGAME       = 4;
    const STATE_CLOSED       = 5;

    protected PlayerTee $playerTee;

    public int $state;

    public function __construct(protected AbstractSocket $socket, protected AbstractWorld $world)
    {
        parent::__construct();
    }

    public function reset(): void
    {
        parent::reset();

        $this->playerTee = new PlayerTee;
        $this->state     = static::STATE_WAITING_INIT;
    }

    public function playerTee(): PlayerTee
    {
        return $this->playerTee;
    }

    public function world(): AbstractWorld
    {
        return $this->world;
    }

    public function feedConnection(AbstractPacket $packet): bool
    {
        if (! $this->validateFeeding($packet)) {
            $this->consoleError('Invalid ack');

            return false;
        }

        if ($packet->isResend()) {
            $this->chunks()->resend();
        }

        if ($packet instanceof ControlMessage) {
            return $this->handleControlMessagePacket($packet);
        }
        if ($packet instanceof DefaultPacket) {
            return $this->handleDefaultPacket($packet);
        }

        return false;
    }

    public function closeConnection(string $reason): void
    {
        $this->sendControlMessage(NetworkMessages::CONTROL_CLOSE, $reason);

        $this->world()->removeTee($this->playerTee);

        $this->reset();
        $this->state = static::STATE_CLOSED;
    }

    protected function handleControlMessagePacket(ControlMessage $packet): bool
    {
        $message = $packet->getControlMessage();

        if ($message === NetworkMessages::CONTROL_CLOSE) {
            $this->consoleInfo('Closed reason='.$packet->getControlMessageExtra());

            $this->world()->removeTee($this->playerTee);

            $this->reset();
            $this->state = static::STATE_CLOSED;
        }

        // CONTROL_KEEP_ALIVE is used just to keep the connection alive
        // by updating the lastRecvTime, since updateConnectionState()
        // already do this, we don't need to do anything here

        return true;
    }

    protected function handleDefaultPacket(DefaultPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            // GameServer()->OnMessage
            if (! $chunk->isSystem()) {
                $this->world()->onMessage($this->playerTee(), $chunk);

                continue;
            }

            if ($chunk instanceof InputChunk) {
                $this->snaps()->setLastAckedTick($chunk->ackGameTick);

                $this->chunks()->add(new InputTimingChunk(
                    intendedTick: $chunk->predictionTick,
                    timeLeft: (int) (($chunk->predictionTick - $this->world()->getCurrentTick()) / NetworkParams::TICKS_PER_SECOND * 1000),
                ));

                // Feed input to the player's tee
                $tee = $this->playerTee();

                // On first input from client, sync prevInputFire to avoid
                // spurious presses from the client's pre-existing m_Fire counter.
                if ($tee->prevInputFire === 0 && $chunk->inputFire !== 0) {
                    $tee->prevInputFire         = $chunk->inputFire;
                    $tee->prevInputWantedWeapon = $chunk->inputWantedWeapon;
                    $tee->prevInputNextWeapon   = $chunk->inputNextWeapon;
                    $tee->prevInputPrevWeapon   = $chunk->inputPrevWeapon;
                }

                $tee->inputDirection = $chunk->inputDirection;
                $tee->inputTargetX   = $chunk->inputTargetX;
                $tee->inputTargetY   = $chunk->inputTargetY;
                $tee->inputJump      = $chunk->inputJump;
                $tee->inputFire      = $chunk->inputFire;
                $tee->inputHook      = $chunk->inputHook;
                $tee->inputWantedWeapon = $chunk->inputWantedWeapon;
                $tee->inputNextWeapon   = $chunk->inputNextWeapon;
                $tee->inputPrevWeapon   = $chunk->inputPrevWeapon;
            }

            // TODO: Implement NETMSG_PING

            // TODO: Implement NETMSG_RCON_CMD

            // TODO: Implement NETMSG_RCON_AUTH
        }

        return true;
    }

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        return $this->socket->sendto($this->destinationAddress, $this->destinationPort, $packet->encodeToSend());
    }

    protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void
    {
        $this->consoleWarn('Unsupported chunk received, system='.(int) $chunk->isSystem().' message='.$chunk->unsupportedMessage);
    }

    protected function handleConnectionOutOfSequence(int $sequence, int $ack): void
    {
        $this->consoleWarn("Out of sequence, asking for resend, {$sequence} - {$ack}");

        $this->sendPacket(new ControlMessage(message: NetworkMessages::CONTROL_KEEP_ALIVE, ack: $this->ack, resend: true));
    }
}
