<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\InputTimingChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\NetworkMessages;
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

    protected int $lastInputTick;

    protected ?int $worldInfoUpdateTick;

    public function __construct(
        protected AbstractSocket $socket,
        protected AbstractWorld $world,
        TickHandler $tickHandler,
    ) {
        parent::__construct($tickHandler);
    }

    public function reset(): void
    {
        parent::reset();

        $this->playerTee = new PlayerTee;
        $this->state     = static::STATE_WAITING_INIT;

        $this->lastInputTick       = -1;
        $this->worldInfoUpdateTick = null;
    }

    public function playerTee(): PlayerTee
    {
        return $this->playerTee;
    }

    public function world(): AbstractWorld
    {
        return $this->world;
    }

    public function changeWorld(AbstractWorld $world): void
    {
        $previousTee = $this->playerTee;
        $this->world->removeTee($previousTee);

        $this->world     = $world;
        $this->playerTee = new PlayerTee;
        $this->playerTee->setInfo(
            $previousTee->name,
            $previousTee->clan,
            $previousTee->country,
            $previousTee->skinName,
            $previousTee->useCustomColor,
            $previousTee->colorBody,
            $previousTee->colorFeet,
        );

        $this->playerTee->latency      = $previousTee->latency;
        $this->playerTee->lastChatTick = $previousTee->lastChatTick;

        $this->state = static::STATE_LOADING;
        $this->snaps()->reset();

        $this->lastInputTick       = -1;
        $this->worldInfoUpdateTick = null;

        // Keep the transport sequence/ACK state: only the map handshake restarts.
        $this->chunks()->add(new MapChangeChunk(...$world->getMapInfo()))->send();
    }

    public function queueWorldInfoUpdate(): void
    {
        // The client polls gameplay before its contact socket. Wait for a snapshot
        // sent after the browser reply, so the world info arrives on a later poll.
        $this->worldInfoUpdateTick = $this->tickHandler->get() + 1;
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
            return $this->state === static::STATE_INGAME ? $this->handleDefaultPacket() : true;
        }

        return false;
    }

    public function closeConnection(string $reason): void
    {
        $this->sendControlMessage(NetworkMessages::CONTROL_CLOSE, $reason);

        $this->world()->removeTee($this->playerTee, $reason);

        $this->reset();
        $this->state = static::STATE_CLOSED;
    }

    protected function handleControlMessagePacket(ControlMessage $packet): bool
    {
        $message = $packet->getControlMessage();

        if ($message === NetworkMessages::CONTROL_CONNECT && $this->state === static::STATE_CONNECTING) {
            $this->sendControlMessage(NetworkMessages::CONTROL_CONNECT_ACCEPT);
        }

        if ($message === NetworkMessages::CONTROL_CLOSE) {
            $this->consoleInfo('Closed reason='.$packet->getControlMessageExtra());

            $this->world()->removeTee($this->playerTee, $packet->getControlMessageExtra());

            $this->reset();
            $this->state = static::STATE_CLOSED;
        }

        // CONTROL_KEEP_ALIVE is used just to keep the connection alive
        // by updating the lastRecvTime, since updateConnectionState()
        // already do this, we don't need to do anything here

        return true;
    }

    protected function handleDefaultPacket(): bool
    {
        foreach ($this->getReceivedChunks() as $chunk) {
            // GameServer()->OnMessage
            if (! $chunk->isSystem()) {
                $this->world()->onMessage($this->playerTee(), $chunk);

                if ($this->state !== static::STATE_INGAME) {
                    break;
                }

                continue;
            }

            if ($chunk instanceof InputChunk) {
                $this->snaps()->setLastAckedTick($chunk->ackGameTick);

                $this->playerTee()->latency = $this->snaps()->getLatency();

                if ($this->worldInfoUpdateTick !== null && $chunk->ackGameTick >= $this->worldInfoUpdateTick) {
                    $this->worldInfoUpdateTick = null;
                    $this->world()->getServer()->sendWorldInfo($this);
                }

                if ($chunk->predictionTick > $this->lastInputTick) {
                    $this->chunks()->add(new InputTimingChunk(
                        intendedTick: $chunk->predictionTick,
                        timeLeft: $this->tickHandler->getTimeUntilTick($chunk->predictionTick),
                    ));
                }

                $this->lastInputTick = $chunk->predictionTick;

                // Buffer the input on the tee, keyed by prediction tick
                $this->playerTee()->inputs[$chunk->predictionTick] = new PlayerInput(
                    direction: $chunk->inputDirection,
                    targetX: $chunk->inputTargetX,
                    targetY: $chunk->inputTargetY,
                    jump: $chunk->inputJump,
                    fire: $chunk->inputFire,
                    hook: $chunk->inputHook,
                    playerFlags: $chunk->inputPlayerFlag,
                    wantedWeapon: $chunk->inputWantedWeapon,
                    nextWeapon: $chunk->inputNextWeapon,
                    prevWeapon: $chunk->inputPrevWeapon,
                );

                // Track activity for the inactive-kick check (CPlayer::OnDirectInput)
                if ($chunk->inputDirection !== 0
                    || $chunk->inputJump
                    || ($chunk->inputFire & 1)
                    || $chunk->inputHook
                ) {
                    $this->playerTee()->lastActionTick = $this->tickHandler->get();
                }
            }

            // NOTE: NETMSG_PING chunk is only sent manually by the client
            // Therefore, is not implemented here

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
