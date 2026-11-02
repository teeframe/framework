<?php

namespace TeeFrame\Server;

use Swoole\Coroutine;
use TeeFrame\Core\IpAddress;
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
use TeeFrame\Server\Chat\MuteList;
use TeeFrame\Server\Master\MasterServer;
use TeeFrame\Server\Rcon\Rcon;
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

    protected MuteList $chatMutes;

    protected Rcon $rcon;

    protected ?MasterServer $masterServer = null;

    protected int|false $tickTimer = false;

    protected bool $shuttingDown = false;

    protected int $startTime;

    public function __construct(
        protected TickHandler $tickHandler,
        protected string $name,
        protected string $password = '',
        protected int $kickBanDuration = 600,
        protected bool $registerOnMasterServer = false,
        #[\SensitiveParameter] string $rconPassword = '',
        int $rconMaxTries = 10,
        int $rconBanDuration = 300,
        int $rconOutputLevel = Console::LEVEL_INFO,
        string $logFile = '',
    ) {
        Console::setLogFile($logFile === '' ? null : $logFile);

        $this->startTime         = $tickHandler->getTime();
        $this->connectionHandler = new ConnectionHandler(tickHandler: $tickHandler);
        $this->banList           = new BanList;
        $this->chatMutes         = new MuteList;
        $this->rcon              = new Rcon($this->connectionHandler, $this->banList, $rconPassword, $rconMaxTries, $rconBanDuration, $rconOutputLevel);
    }

    abstract protected function boot(): void;

    abstract protected function selectWorldForNewConnection(): AbstractWorld;

    public function getUptime(): int
    {
        return intdiv($this->tickHandler->getTime() - $this->startTime, 1_000_000_000);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getChatMutes(): MuteList
    {
        return $this->chatMutes;
    }

    public function getBanList(): BanList
    {
        return $this->banList;
    }

    /** @return list<ConnectionSlot> */
    public function getConnections(): array
    {
        return array_values(array_filter($this->connectionHandler->getConnections(), fn (ConnectionSlot $connection): bool => $connection->state !== ConnectionSlot::STATE_WAITING_INIT && $connection->state !== ConnectionSlot::STATE_CLOSED));
    }

    public function getConnection(AbstractTee $tee): ?ConnectionSlot
    {
        return array_find($this->getConnections(), fn (ConnectionSlot $connection): bool => $connection->playerTee() === $tee);
    }

    public function resolveClientAddress(AbstractWorld $world, string $target): string
    {
        $address = IpAddress::normalize($target);
        if ($address !== null) {
            return $address;
        }
        $id         = filter_var($target, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $player     = $id === false ? null : $world->findTee($id);
        $connection = $player === null ? null : $this->getConnection($player);
        $address    = $connection === null ? null : IpAddress::normalize($connection->destinationAddress);

        return $address ?? throw new \InvalidArgumentException('Expected an IP address or a player id in this world');
    }

    public function getRcon(): Rcon
    {
        return $this->rcon;
    }

    /** @return AbstractWorld[] */
    public function getWorlds(): array
    {
        return $this->worlds;
    }

    public function start(): void
    {
        Console::info('server', 'Starting server "'.$this->name.'" ('.NetworkParams::TICKS_PER_SECOND.' ticks/s)');

        $this->boot();

        if (empty($this->worlds)) {
            throw new \RuntimeException('No worlds were booted');
        }

        if (empty($this->sockets)) {
            throw new \RuntimeException('No sockets were booted');
        }

        foreach ($this->worlds as $world) {
            $world->consoleInfo('world', 'Map='.$world->getMap()->getName().' Gametype='.$world->getGameController()->getGameType().' Slots='.$world->getSlotsLimit());
        }

        $this->bootMasterServerRegistration();

        $this->tickTimer = swoole_timer_tick(1000 / NetworkParams::TICKS_PER_SECOND, function (): void {
            $this->doTick();
        });

        foreach ($this->sockets as $socket) {
            $socketType = match ($socket->type & ~SWOOLE_SSL) {
                SWOOLE_SOCK_TCP  => 'TCP/IPv4',
                SWOOLE_SOCK_TCP6 => 'TCP/IPv6',
                SWOOLE_SOCK_UDP  => 'UDP/IPv4',
                SWOOLE_SOCK_UDP6 => 'UDP/IPv6',
                default          => 'type '.$socket->type,
            };

            $socket->on('start', function () use ($socket, $socketType): void {
                Console::info('network', 'Server is listening on '.$socket->host.' port '.$socket->port.' ('.$socketType.')');
            });

            $socket->on('shutdown', function () use ($socket, $socketType): void {
                Console::info('network', 'Server stopped listening on '.$socket->host.' port '.$socket->port.' ('.$socketType.')');
            });

            $socket->on('packet', function (mixed $server, string $data, array $clientInfo) use ($socket): void {
                $packet = PacketDecoder::decodeFromRaw($data);
                if ($packet === false) {
                    Console::trace('network', 'Discarded malformed packet Address='.$clientInfo['address'].' Port='.$clientInfo['port'].' Bytes='.strlen($data));

                    return;
                }

                /** @var array{address: string, port: int} $clientInfo */
                $this->onPacket($packet, $clientInfo, $socket);
            });

            $socket->on('workerExit', function (): void {
                $this->shuttingDown = true;
                $this->stopTicking();
                $this->rcon->stop();
            });

            $socket->on('workerStop', function () use ($socket): void {
                $this->masterServer?->onWorkerStop($socket);
            });

            $socket->start();
        }
    }

    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        $this->stopTicking();
        Console::info('server', 'Shutting down server "'.$this->name.'"');
        $this->rcon->update();
        $this->rcon->stop();

        $shutdown = function (): void {
            $this->masterServer?->shutdown();

            foreach ($this->sockets as $socket) {
                $socket->shutdown();
            }
        };

        if ($this->masterServer === null) {
            $shutdown();
        } else {
            Coroutine::create($shutdown);
        }
    }

    protected function stopTicking(): void
    {
        if ($this->tickTimer !== false) {
            swoole_timer_clear($this->tickTimer);
            $this->tickTimer = false;
        }
    }

    protected function bootMasterServerRegistration(): void
    {
        Console::info('register', 'Master server registration: '.($this->registerOnMasterServer ? 'enabled' : 'disabled'));

        if (! $this->registerOnMasterServer) {
            return;
        }

        $this->masterServer = $this->createMasterServer();
        $this->masterServer->start($this->sockets);
    }

    protected function createMasterServer(): MasterServer
    {
        return new MasterServer($this->tickHandler);
    }

    protected function doTick(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->tickHandler->startTick();
        $this->connectionHandler->update();
        $this->rcon->update();

        foreach ($this->worlds as $world) {
            $world->doTick();
        }

        $this->doSnap();

        $this->tickHandler->next();

        if ($this->tickHandler->get() >= NetworkParams::MAXIMUM_TICK) {
            $this->shutdown();
        }

        if (! $this->shuttingDown) {
            $this->masterServer?->update(
                $this->worlds,
                $this->connectionHandler->getConnections(),
                $this->name,
                $this->password !== '',
            );
        }
    }

    protected function doSnap(): void
    {
        $currentTick = $this->tickHandler->get();

        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME || ! $connection->snaps()->shouldSend($currentTick)) {
                continue;
            }

            $connection->snaps()->sendItems(
                currentTick: $currentTick,
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

    public function sendToWorld(AbstractWorld $world, AbstractChunk $chunk, bool $includeReady = false): void
    {
        foreach ($this->getConnections() as $connection) {
            if ($connection->world() === $world
                && ($connection->state === ConnectionSlot::STATE_INGAME
                    || ($includeReady && $connection->state === ConnectionSlot::STATE_READY && $connection->readyToEnter))
            ) {
                $connection->chunks()->add(clone $chunk)->send();
            }
        }
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
            if ($this->masterServer?->onPacket($packet, $socket)) {
                return;
            }

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
            Console::debug('network', 'Connection rejected Address='.$clientInfo['address'].' Port='.$clientInfo['port'].' Reason=banned');
            $ban = $this->banList->getBan($clientInfo['address']);

            if ($ban === null) {
                return; // Impossible scenario, TODO: Refactor this to avoid this null check
            }

            $duration = $ban->expiry === null ? 'permanently' : 'for '.(int) ceil(($ban->expiry - time()) / 60).' more minute(s)';
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'You are banned '.$duration.': '.($ban->reason ?? 'Kicked by vote')))->encodeToSend()
            );

            return;
        }

        // New connection...
        $slotConnection = $this->connectionHandler->startNew($socket, $this->selectWorldForNewConnection());
        if (! $slotConnection) {
            $reason = count($this->worlds) > 1
                ? 'The entry-level world is full'
                : 'The server is full';
            Console::debug('network', 'Connection rejected Address='.$clientInfo['address'].' Port='.$clientInfo['port'].' Reason='.$reason);

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
