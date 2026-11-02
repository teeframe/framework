<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\InputTimingChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\Rcon\RconSession;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionSlot extends AbstractConnection
{
    const STATE_WAITING_INIT = 0;
    const STATE_CONNECTING   = 1;
    const STATE_LOADING      = 2;
    const STATE_READY        = 3;
    const STATE_INGAME       = 4;
    const STATE_CLOSED       = 5;

    protected PlayerTee $playerTee;

    protected RconSession $rconSession;

    public int $state;
    public bool $readyToEnter;

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

        $this->rconSession  = new RconSession;
        $this->playerTee    = new PlayerTee;
        $this->state        = static::STATE_WAITING_INIT;
        $this->readyToEnter = false;

        $this->lastInputTick       = -1;
        $this->worldInfoUpdateTick = null;
    }

    public function playerTee(): PlayerTee
    {
        return $this->playerTee;
    }

    public function rcon(): RconSession
    {
        return $this->rconSession;
    }

    public function handleRconChunk(AbstractChunk $chunk): bool
    {
        if (! $chunk instanceof RconAuthChunk && ! $chunk instanceof RconCmdChunk) {
            return false;
        }

        $this->world()->getServer()->getRcon()->handle($this, $chunk);

        return true;
    }

    public function world(): AbstractWorld
    {
        return $this->world;
    }

    public function changeWorld(AbstractWorld $world): void
    {
        if ($this->world !== $world) {
            $this->consoleInfo('world', 'Changing world to '.$world->identifier);
        }
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

        $this->state        = static::STATE_LOADING;
        $this->readyToEnter = false;
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
        $this->worldInfoUpdateTick = $this->tickHandler->getCurrentTick() + 1;
    }

    public function feedConnection(AbstractPacket $packet): bool
    {
        if (! $this->acceptIncomingPacket($packet)) {
            $this->consoleDebug('network', 'Invalid ack');

            return false;
        }

        if ($packet->requestsResend()) {
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
        $this->consoleInfo('network', 'Closing connection: '.$reason);
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
            $reason = Utf8::truncateBytes(explode("\0", $packet->getControlMessageExtra(), 2)[0], 127);
            $this->consoleInfo('network', 'Closed reason='.$reason);

            $this->world()->removeTee($this->playerTee, $reason);

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
            if ($this->handleRconChunk($chunk)) {
                if ($this->state !== static::STATE_INGAME) {
                    break;
                }

                continue;
            }

            // GameServer()->OnMessage
            if (! $chunk->isSystem()) {
                $this->world()->onMessage($this->playerTee(), $chunk);

                if ($this->state !== static::STATE_INGAME) {
                    break;
                }

                continue;
            }

            if ($chunk instanceof InputChunk) {
                $this->snaps()->handleSnapshotAck($chunk->ackGameTick);

                $this->playerTee()->latency = $this->snaps()->getLatency();

                if ($this->worldInfoUpdateTick !== null && $chunk->ackGameTick >= $this->worldInfoUpdateTick) {
                    $this->worldInfoUpdateTick = null;
                    $this->world()->getServer()->sendWorldInfo($this);
                }

                if ($chunk->predictionTick > $this->lastInputTick) {
                    $this->chunks()->add(new InputTimingChunk(
                        intendedTick: $chunk->predictionTick,
                        timeLeft: intdiv($this->tickHandler->getTimeUntilTick($chunk->predictionTick), 1_000_000),
                    ));
                }

                $this->lastInputTick = $chunk->predictionTick;

                $input = new PlayerInput(
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

                // Between callbacks, the clock already points to the next unprocessed tick.
                // Keep the client's original tick in INPUTTIMING.
                $this->playerTee()->bufferInput(max($chunk->predictionTick, $this->tickHandler->getCurrentTick()), $input);

                if (! $this->world()->isPaused()) {
                    $this->playerTee()->onDirectInput($input, $this->tickHandler->getCurrentTick());
                }
            }

            // NOTE: NETMSG_PING chunk is only sent manually by the client
            // Therefore, is not implemented here
        }

        return true;
    }

    public function consoleError(string $system, string $message): void
    {
        $this->world()->consoleError($system, $this->generateConsoleMessage($message));
    }

    public function consoleWarn(string $system, string $message): void
    {
        $this->world()->consoleWarn($system, $this->generateConsoleMessage($message));
    }

    public function consoleInfo(string $system, string $message): void
    {
        $this->world()->consoleInfo($system, $this->generateConsoleMessage($message));
    }

    public function consoleDebug(string $system, string $message): void
    {
        $this->world()->consoleDebug($system, $this->generateConsoleMessage($message));
    }

    protected function generateConsoleMessage(string $message): string
    {
        $address = str_contains($this->destinationAddress, ':') ? '['.$this->destinationAddress.']' : $this->destinationAddress;

        return 'TeeIdx='.$this->playerTee()->teeIndex.' addr='.$address.':'.$this->destinationPort.' '.$message;
    }

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        $sent = $this->socket->sendto($this->destinationAddress, $this->destinationPort, $packet->encodeToSend());

        if (! $sent) {
            $this->consoleWarn('network', 'Failed to send packet');
        }

        return $sent;
    }

    protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void
    {
        $this->consoleDebug('network', 'Unsupported chunk received, system='.(int) $chunk->isSystem().' message='.$chunk->unsupportedMessage);
    }

    protected function handleConnectionOutOfSequence(int $sequence, int $ack): void
    {
        $this->consoleDebug('network', "Out of sequence, asking for resend, {$sequence} - {$ack}");

        $this->sendPacket(new ControlMessage(message: NetworkMessages::CONTROL_KEEP_ALIVE, ack: $this->ack, resend: true));
    }
}
