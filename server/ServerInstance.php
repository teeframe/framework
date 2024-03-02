<?php

namespace TeeFrame\Server;

use Server\ConnectionHandler;
use TeeFrame\Game\Core\TickHandler;
use TeeFrame\Game\GameWorld;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Server\Connection\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class ServerInstance
{
    /**
     * @var GameWorld[]
     */
    protected array $worlds = [];

    /**
     * @var AbstractSocket[]
     */
    protected array $sockets = [];

    protected ConnectionHandler $connectionHandler;

    public function __construct(protected TickHandler $tickHandler)
    {
        $this->connectionHandler = new ConnectionHandler(slotsLimit: 64);
    }

    public function addSocket(AbstractSocket $socket)
    {
        $this->sockets[] = $socket;
    }

    public function addWorld(GameWorld $world)
    {
        $this->worlds[] = $world;
    }

    public function start()
    {
        if (empty($this->worlds)) {
            throw new \RuntimeException('No worlds to start');
        }

        if (empty($this->sockets)) {
            throw new \RuntimeException('No sockets to start');
        }

        swoole_timer_tick(1000 / NetworkParams::TICKS_PER_SECOND, function (): void {
            $this->onTick();
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

    protected function onTick(): void
    {
        foreach ($this->worlds as $world) {
            $world->tick();
        }

        $this->onSnap();

        $this->tickHandler->next();

        if ($this->tickHandler->get() >= NetworkParams::MAXIMUM_TICK) {
            $this->shutdown();
        }
    }

    protected function onSnap(): void
    {
        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $connection->snaps()->sendItems($this->tickHandler->get(), [
                ...$this->doSnap($slotIndex),
                ...$this->doConnectionsSnap($slotIndex),
            ]);
        }

        foreach ($this->worlds as $world) {
            $world->clearEvents();
        }
    }

    protected function onPacket(AbstractPacket $packet, array $clientInfo, AbstractSocket $socket)
    {
        
    }

    protected function selectWorldForNewConnection(): GameWorld
    {
        return $this->worlds[0];
    }
}