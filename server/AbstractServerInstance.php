<?php

namespace TeeFrame\Server;

use Swoole\Coroutine;
use TeeFrame\Core\IpAddress;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\Ban\BanList;
use TeeFrame\Server\Chat\MuteList;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\ConfigOption;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\Master\MasterServer;
use TeeFrame\Server\Rcon\Rcon;
use TeeFrame\Server\Sockets\AbstractSocket;
use TeeFrame\Server\Sockets\UdpSocket;

/** @phpstan-import-type Definition from Config */
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
    protected bool $shuttingDown   = false;
    protected int $startTime;
    protected Config $config;
    protected bool $started        = false;
    protected bool $hadConnections = false;
    protected int $infoReplyWindow = 0;
    protected int $infoReplies     = 0;

    public function __construct(
        protected TickHandler $tickHandler,
        #[\SensitiveParameter] Config|string $config = new Config,
        public readonly bool $activatePvpSettings = false,
    ) {
        $this->config = is_string($config) ? new Config : $config;

        $this->bootConfig();

        if (is_string($config)) {
            $this->config->loadFile($config, activatePvpSettings: $this->activatePvpSettings);
        }

        $this->validateConfig($this->config);

        $logFile = $this->config->getString('sv_log_file');

        try {
            Console::setLogFile($logFile === '' ? null : $logFile, $this->config->getInt('sv_log_file_output_level') + 2);
        } catch (\RuntimeException) {
            Console::error('config', 'Cannot load configuration setting sv_log_file: cannot open log file');
            $this->config->set('sv_log_file', '');
        }

        if ($this->config->has('sv_console_output_level')) {
            Console::setOutputLevel($this->config->getInt('sv_console_output_level') + 2);
        }

        if ($this->config->has('sv_console_colors')) {
            Console::setColors($this->config->getInt('sv_console_colors') ? null : false);
        }

        foreach ($this->config->getLoadedFiles() as $path) {
            Console::info('config', 'Loaded configuration file: '.$path);
        }

        $this->startTime         = $tickHandler->getTime();
        $this->connectionHandler = new ConnectionHandler(tickHandler: $tickHandler);

        $this->banList   = new BanList;
        $this->chatMutes = new MuteList;
        $this->rcon      = new Rcon(
            $this->connectionHandler,
            $this->banList,
            $this->config->getString('sv_rcon_password'),
            $this->config->getInt('sv_rcon_max_tries'),
            $this->config->getInt('sv_rcon_ban_duration') * 60,
            $this->config->getInt('sv_rcon_output_level') + 2,
        );
    }

    protected function bootConfig(): void
    {
        // no-op by default

        // NOTE: This is where you can register custom config options
        // before the config file is loaded.
    }

    public function registerConfigOption(ConfigOption $option): void
    {
        $this->config->registerOption($option);
        $name = strtolower($option->name);

        if (isset($this->getConfigDefinitions()[$name])) {
            foreach ($this->worlds as $world) {
                $world->registerConfigCommand($name);
            }
        }
    }

    protected function boot(): void
    {
        $this->sockets[] = new UdpSocket(
            $this->config->getString('sv_bind_address'),
            $this->config->getInt('sv_port'),
        );
    }

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return array_first($this->worlds) ?? throw new \RuntimeException('No worlds are available for new connections');
    }

    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        throw new \RuntimeException('Implement createWorld() to load worlds from configuration');
    }

    protected function bootConfiguredWorlds(): void
    {
        foreach ($this->config->getWorlds() as $config) {
            try {
                $this->addWorldFromConfig($config);
            } catch (\InvalidArgumentException|\RuntimeException) {
                Console::error('config', 'Cannot load configured world '.$config->identifier.': world creation failed');
            }
        }
    }

    public function addWorldFromConfig(WorldConfig $config): AbstractWorld
    {
        if (array_any($this->worlds, fn (AbstractWorld $world): bool => $world->identifier === $config->identifier)) {
            throw new \InvalidArgumentException('World already exists: '.$config->identifier);
        }

        $this->validateConfig($config->settings);

        $world          = $this->createWorld($config);
        $this->worlds[] = $world;

        return $world;
    }

    public function getUptime(): int
    {
        return intdiv($this->tickHandler->getTime() - $this->startTime, 1_000_000_000);
    }

    public function getName(): string
    {
        return $this->config->getString('sv_name');
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /** @return array<string, Definition> */
    public function getConfigDefinitions(): array
    {
        return $this->config->getDefinitions($this->activatePvpSettings);
    }

    /** @return Definition */
    public function getConfigDefinition(string $name): array
    {
        return $this->config->getDefinition($name, $this->activatePvpSettings);
    }

    public function validateConfig(Config $config): void
    {
        $config->assertPvpSettingsEnabled($this->activatePvpSettings);
    }

    public function assertConfigMutable(string $name): void
    {
        $name       = strtolower($name);
        $definition = $this->getConfigDefinition($name);

        if ($this->started && ($definition['restart'] ?? false)) {
            throw new \InvalidArgumentException($name.' requires a server restart; set it in the startup config');
        }
    }

    public function getConfigValue(string $name): int|string
    {
        $this->getConfigDefinition($name);

        return $this->config->get($name);
    }

    public function setConfig(string $name, #[\SensitiveParameter] int|string $value): void
    {
        $name       = strtolower($name);
        $definition = $this->getConfigDefinition($name);
        $value      = $this->config->validateValue($name, $value);

        $this->assertConfigMutable($name);

        if ($definition['scope'] === 'world' && $this->started) {
            throw new \InvalidArgumentException('Change world settings through the world, not the server');
        }

        if ($name === 'sv_rcon_password') {
            $this->rcon->configureAuthentication(password: (string) $value);
        } elseif ($name === 'sv_rcon_max_tries') {
            $this->rcon->configureAuthentication(maxTries: (int) $value);
        } elseif ($name === 'sv_rcon_ban_duration') {
            $this->rcon->configureAuthentication(banDuration: (int) $value * 60);
        } elseif ($name === 'sv_rcon_output_level') {
            $this->rcon->setOutputLevel((int) $value + 2);
        } elseif ($name === 'sv_console_output_level') {
            Console::setOutputLevel((int) $value + 2);
        } elseif ($name === 'sv_console_colors') {
            Console::setColors($value ? null : false);
        } elseif ($name === 'sv_log_file' || $name === 'sv_log_file_output_level') {
            $path  = $name === 'sv_log_file' ? (string) $value : $this->config->getString('sv_log_file');
            $level = $name === 'sv_log_file_output_level' ? (int) $value : $this->config->getInt('sv_log_file_output_level');
            Console::setLogFile($path === '' ? null : $path, $level + 2);
        }

        $this->config->set($name, $value);
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
        return array_values(array_filter(
            $this->connectionHandler->getConnections(),
            fn (ConnectionSlot $connection): bool => $connection->state !== ConnectionSlot::STATE_WAITING_INIT
                && $connection->state !== ConnectionSlot::STATE_CLOSED
        ));
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
        Console::info('server', 'Starting server "'.$this->getName().'" ('.NetworkParams::TICKS_PER_SECOND.' ticks/s)');

        $this->boot();
        $this->bootConfiguredWorlds();
        $this->started = true;

        if (empty($this->worlds)) {
            $this->started = false;
            Console::error('server', 'Cannot start server: no valid worlds were loaded');

            return;
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
                /** @var array{address: string, port: int} $clientInfo */
                $packet = PacketDecoder::decodeFromRaw($data);

                if ($packet === false) {
                    Console::trace('network', 'Discarded malformed packet Address='.$clientInfo['address'].' Port='.$clientInfo['port'].' Bytes='.strlen($data));

                    return;
                }

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
        Console::info('server', 'Shutting down server "'.$this->getName().'"');
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
        $enabled = (bool) $this->config->getInt('sv_register_on_master_server');
        Console::info('register', 'Master server registration: '.($enabled ? 'enabled' : 'disabled'));

        if (! $enabled) {
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

        if ($this->hadConnections && $this->config->getInt('sv_shutdown_when_empty') && $this->getConnections() === []) {
            $this->shutdown();

            return;
        }

        foreach ($this->worlds as $world) {
            $world->doTick();
        }

        $this->doSnap();

        $this->tickHandler->advanceTick();

        if ($this->tickHandler->getCurrentTick() >= NetworkParams::MAXIMUM_TICK) {
            $this->shutdown();
        }

        if (! $this->shuttingDown) {
            $this->masterServer?->update(
                $this->worlds,
                $this->connectionHandler->getConnections(),
                $this->getName(),
                $this->config->getString('sv_password') !== '',
            );
        }
    }

    protected function doSnap(): void
    {
        $currentTick = $this->tickHandler->getCurrentTick();

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
        // MOTD and running-vote state are sent at READY; vote options and tuning
        // are initialized later, when the client's start info arrives.
        foreach ($this->getConnections() as $connection) {
            if ($connection->world() === $world
                && ($connection->state === ConnectionSlot::STATE_INGAME
                    || ($includeReady && $connection->state === ConnectionSlot::STATE_READY
                        && ($connection->readyToEnter || $chunk instanceof SvMotdChunk
                                                    || $chunk instanceof SvVoteSetChunk || $chunk instanceof SvVoteStatusChunk)))
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

    public function kick(AbstractWorld $world, int $teeIndex, string $reason, ?int $banDuration = null): void
    {
        $connection = $this->connectionHandler->findByTee($world, $teeIndex);

        if ($connection === null) {
            return;
        }

        // Ban the address so they can't reconnect immediately.
        $banDuration ??= $this->config->getInt('sv_kick_ban_duration') * 60;

        if ($banDuration > 0) {
            $this->banList->ban($connection->destinationAddress, $banDuration, $reason);
        }
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

        if ($connectionSlot = $this->connectionHandler->findByEndpoint($clientInfo, $socket)) {
            if ($connectionSlot->state !== ConnectionSlot::STATE_INGAME) {
                $this->connectionHandler->handleConnectionHandshake($connectionSlot, $packet, $this->config->getString('sv_password'));

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
        if (($ban = $this->banList->getBan($clientInfo['address'])) !== null) {
            Console::debug('network', 'Connection rejected Address='.$clientInfo['address'].' Port='.$clientInfo['port'].' Reason=banned');

            $duration = $ban->expiry === null ? 'permanently' : 'for '.(int) ceil(($ban->expiry - time()) / 60).' more minute(s)';
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'You are banned '.$duration.': '.($ban->reason ?? 'Kicked by vote')))->encodeToSend()
            );

            return;
        }

        $address = IpAddress::normalize($clientInfo['address']) ?? $clientInfo['address'];
        $sameIp  = array_filter($this->getConnections(), fn (ConnectionSlot $slot): bool => (IpAddress::normalize($slot->destinationAddress) ?? $slot->destinationAddress) === $address);

        $perIpLimit = $this->config->getInt('sv_max_clients_per_ip');

        if ($perIpLimit > 0 && count($sameIp) >= $perIpLimit) {
            $socket->sendto($clientInfo['address'], $clientInfo['port'], (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'Too many clients from this IP address'))->encodeToSend());

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
        $this->hadConnections = true;
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

        $now = $this->tickHandler->getTime();

        if ($now - $this->infoReplyWindow >= 1_000_000_000) {
            $this->infoReplyWindow = $now;
            $this->infoReplies     = 0;
        }

        $limit = $this->config->getInt('sv_server_info_replies_per_second');

        if ($limit > 0 && $this->infoReplies >= $limit) {
            return;
        }

        $connection = $this->connectionHandler->findByEndpoint($clientInfo, $socket);
        $worlds     = $connection && $connection->state !== ConnectionSlot::STATE_CLOSED
            ? [$connection->world()]
            : $this->worlds;

        if ($worlds === []) {
            return;
        }

        $token = ord($data[strlen($header)]) | ($extraToken << 8);

        foreach ($this->buildServerInfoPackets($worlds, $token) as $reply) {
            if ($limit > 0 && $this->infoReplies >= $limit) {
                break;
            }

            $this->infoReplies++;
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
                if ($slot->socket() === $socket && $slot->destinationAddress === $clientInfo['address'] && $slot->state !== ConnectionSlot::STATE_CLOSED) {
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
            name: $this->getName(),
            passwordProtected: $this->config->getString('sv_password') !== '',
            token: $token,
        );
    }
}
