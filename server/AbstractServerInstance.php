<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\Ban\BanList;
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
    protected BanList $banList;

    public function __construct(
        protected TickHandler $tickHandler,
        protected string $name,
        protected string $password = '',
        protected int $kickBanDuration = 600,
    ) {
        $this->connectionHandler = new ConnectionHandler(tickHandler: $tickHandler);
        $this->banList           = new BanList;
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

                /** @var array{address: string, port: int} $clientInfo */
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
        $this->tickHandler->startTick();
        $this->connectionHandler->update();

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

    public function sendToTee(AbstractWorld $world, int $teeIndex, AbstractChunk $chunk): void
    {
        $connection = $this->connectionHandler->findByTee($world, $teeIndex);
        $connection?->chunks()->add($chunk)->send();
    }

    public function changeWorld(AbstractTee $tee, AbstractWorld $world): bool
    {
        if (! in_array($world, $this->worlds, true)) {
            return false;
        }

        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME || $connection->playerTee() !== $tee) {
                continue;
            }

            if ($connection->world() === $world) {
                return true;
            }

            if ($this->connectionHandler->isWorldFull($world)) {
                return false;
            }

            $connection->changeWorld($world);

            return true;
        }

        return false;
    }

    public function reloadWorld(AbstractWorld $world): void
    {
        foreach ($this->connectionHandler->getConnections() as $connection) {
            // CONNECTING clients must still pass version/password validation.
            if ($connection->world() === $world
                && $connection->state >= ConnectionSlot::STATE_LOADING
                && $connection->state <= ConnectionSlot::STATE_INGAME
            ) {
                $connection->changeWorld($world);
            }
        }
    }

    public function trySetClientName(AbstractWorld $world, AbstractTee $tee, string $name): bool
    {
        return $world->trySetTeeName($tee, $name);
    }

    public function setClientName(AbstractWorld $world, AbstractTee $tee, string $name): void
    {
        $world->setTeeName($tee, $name);
    }

    public function kick(AbstractWorld $world, int $teeIndex, string $reason): void
    {
        $connection = $this->connectionHandler->findByTee($world, $teeIndex);
        if ($connection === null) {
            return;
        }

        // Ban the address so they can't reconnect immediately.
        $this->banList->ban($connection->destinationAddress, $this->kickBanDuration, $reason);
        $connection->closeConnection($reason);
    }

    /**
     * @param  array{address: string, port: int}  $clientInfo
     */
    protected function onPacket(AbstractPacket $packet, array $clientInfo, AbstractSocket $socket): void
    {
        if ($packet instanceof ConnectionlessPacket) {
            $this->handleServerInfoRequest($packet, $clientInfo, $socket);

            return;
        }

        if ($connectionSlot = $this->connectionHandler->tryToMatch($clientInfo)) {
            if ($connectionSlot->state !== ConnectionSlot::STATE_INGAME) {
                $this->connectionHandler->handleConnectionHandshake($connectionSlot, $packet, $this->password);

                return;
            }

            $connectionSlot->feedConnection($packet);

            return;
        }

        // Is not a connect control message...
        if (! ($packet instanceof ControlMessage)) {
            return;
        }

        if ($packet->getControlMessage() !== NetworkMessages::CONTROL_CONNECT) {
            return;
        }

        // Ban system: reject connections from banned addresses
        if ($this->banList->isBanned($clientInfo['address'])) {
            $ban = $this->banList->getBan($clientInfo['address']);

            if ($ban === null) {
                return; // Impossible scenario, TODO: Refactor this to avoid this null check
            }

            $minutesLeft = (int) ceil(($ban->expiry - time()) / 60);
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'You are banned for '.$minutesLeft.' more minute(s): '.($ban->reason ?? 'Kicked by vote')))->encodeToSend()
            );

            return;
        }

        // New connection...
        $slotConnection = $this->connectionHandler->startNew($socket, $this->selectWorldForNewConnection());
        if (! $slotConnection) {
            $reason = count($this->worlds) > 1
                ? 'The entry-level world is full'
                : 'The server is full';

            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, $reason))->encodeToSend()
            );

            return;
        }

        $this->connectionHandler->startConnectionHandshake($slotConnection, $clientInfo['address'], $clientInfo['port']);
    }

    /**
     * @param  array{address: string, port: int}  $clientInfo
     */
    protected function handleServerInfoRequest(ConnectionlessPacket $packet, array $clientInfo, AbstractSocket $socket): void
    {
        $data       = $packet->getData();
        $header     = NetworkMessages::SERVERBROWSE_GETINFO;
        $extraToken = $packet->getExtraToken();

        if ($extraToken === null || strlen($data) !== strlen($header) + 1 || ! str_starts_with($data, $header)) {
            return;
        }

        $connection = $this->connectionHandler->tryToMatch($clientInfo);
        $worlds     = $connection && $connection->state !== ConnectionSlot::STATE_CLOSED
            ? [$connection->world()]
            : $this->worlds;

        if ($worlds === []) {
            return;
        }

        $token = ord($data[strlen($header)]) | ($extraToken << 8);

        foreach ($this->buildServerInfoPackets($worlds, $token) as $reply) {
            $socket->sendto($clientInfo['address'], $clientInfo['port'], $reply->encodeToSend());
        }

        // The client queries server info through a separate contact socket, so
        // the query does not match its gameplay connection. The aggregate reply
        // can overwrite its in-game Server tab with MW and the total capacity.
        // Queue a world-specific update on the gameplay socket to restore it,
        // deferred until a newer snapshot ACK to avoid a same-poll overwrite.
        //
        // The query contains no player/world identifier. The IP only filters
        // which connections to refresh; it never selects a world. Players
        // sharing an IP therefore all get a refresh, each for their own world.
        if (! $connection || $connection->state === ConnectionSlot::STATE_CLOSED) {
            foreach ($this->connectionHandler->getConnections() as $slot) {
                if ($slot->destinationAddress === $clientInfo['address'] && $slot->state !== ConnectionSlot::STATE_CLOSED) {
                    $slot->queueWorldInfoUpdate();
                }
            }
        }
    }

    public function sendWorldInfo(ConnectionSlot $connection): void
    {
        if ($connection->state !== ConnectionSlot::STATE_INGAME) {
            return;
        }

        foreach ($this->buildServerInfoPackets([$connection->world()], -1) as $packet) {
            $connection->sendPacket($packet);
        }
    }

    /**
     * @param  non-empty-array<AbstractWorld>  $worlds
     * @return list<ConnectionlessPacket>
     */
    protected function buildServerInfoPackets(array $worlds, int $token): array
    {
        return (new ServerInfoPacketBuilder)->build(
            worlds: $worlds,
            connections: $this->connectionHandler->getConnections(),
            name: $this->name,
            passwordProtected: $this->password !== '',
            token: $token,
        );
    }
}
