<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\Sockets\AbstractSocket;

abstract class AbstractServerInstance
{
    /**
     * @var AbstractWorld[]
     */
    protected array $worlds = [];

    /**
     * @var AbstractSocket[]
     */
    protected array $sockets = [];

    protected ConnectionHandler $connectionHandler;

    public function __construct(protected TickHandler $tickHandler, protected string $password = '')
    {
        $this->connectionHandler = new ConnectionHandler(slotsLimit: 64);
    }

    abstract protected function boot(): void;

    abstract protected function selectWorldForNewConnection(): AbstractWorld;

    public function start(): void
    {
        $this->boot();

        if (empty($this->worlds)) {
            throw new \RuntimeException('No worlds were booted');
        }

        if (empty($this->sockets)) {
            throw new \RuntimeException('No sockets were booted');
        }

        swoole_timer_tick(1000 / NetworkParams::TICKS_PER_SECOND, function (): void {
            $this->doTick();
        });

        foreach ($this->sockets as $socket) {
            $socket->on('packet', function (mixed $server, string $data, array $clientInfo) use ($socket): void {
                $packet = PacketDecoder::decodeFromRaw($data);
                if ($packet === false) {
                    return;
                }

                $this->onPacket($packet, $clientInfo, $socket);
            });

            $socket->start();
        }
    }

    public function shutdown(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->shutdown();
        }
    }

    protected function doTick(): void
    {
        foreach ($this->worlds as $world) {
            $world->doTick();
        }

        $this->doSnap();

        $this->tickHandler->next();

        if ($this->tickHandler->get() >= NetworkParams::MAXIMUM_TICK) {
            $this->shutdown();
        }

        // TODO: master server stuff
    }

    protected function doSnap(): void
    {
        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $connection->snaps()->sendItems(
                currentTick: $this->tickHandler->get(),
                rawItems: $connection->world()->doSnap($connection->playerTee()),
            );
        }

        foreach ($this->worlds as $world) {
            $world->clearEvents();
        }
    }

    protected function onPacket(AbstractPacket $packet, array $clientInfo, AbstractSocket $socket): void
    {
        if ($connectionSlot = $this->connectionHandler->tryToMatch($clientInfo)) {
            if ($connectionSlot->state !== ConnectionSlot::STATE_INGAME) {
                $this->connectionHandler->handleConnectionHandshake($connectionSlot, $packet, $this->password);

                return;
            }

            $connectionSlot->feedConnection($packet);
        }

        // Is not a connect control message...
        if (! ($packet instanceof ControlMessage)) {
            return;
        }

        if ($packet->getControlMessage() !== NetworkMessages::CONTROL_CONNECT) {
            return;
        }

        // TODO: Implement ban system

        // New connection...
        $slotConnection = $this->connectionHandler->startNew($socket, $this->selectWorldForNewConnection());
        if (! $slotConnection) {
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'The server is full'))->encodeToSend()
            );

            return;
        }

        $this->connectionHandler->startConnectionHandshake($slotConnection, $clientInfo['address'], $clientInfo['port']);
    }
}
