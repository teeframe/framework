<?php

namespace Tests\Support;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class RconTestCommand extends AbstractCommand
{
    /** @var list<array{world: AbstractWorld, tee: AbstractTee, arguments: list<string>}> */
    public array $executions = [];

    /** @param (\Closure(AbstractWorld, AbstractTee, list<string>): void)|null $handler */
    public function __construct(
        public string $name = 'record',
        public int $type = self::TYPE_SERVER,
        public string $description = '',
        public string $params = '',

        protected ?\Closure $handler = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description === '' ? parent::getDescription() : $this->description;
    }

    public function getParams(): string
    {
        return $this->params;
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $this->executions[] = ['world' => $world, 'tee' => $tee, 'arguments' => $arguments];
        if ($this->handler !== null) {
            ($this->handler)($world, $tee, $arguments);
        }
    }
}

class RconTestSocket extends AbstractSocket
{
    /** @var list<AbstractPacket> */
    public array $packets = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($send_data));
        $packet = PacketDecoder::decodeFromRaw($send_data);
        if ($packet === false) {
            throw new \RuntimeException('Server emitted an invalid RCON packet');
        }
        $this->packets[] = $packet;

        return true;
    }

    /**
     * @template T of AbstractChunk
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function chunks(string $class): array
    {
        $chunks = [];
        foreach ($this->packets as $packet) {
            if ($packet instanceof DefaultPacket) {
                foreach ($packet->getChunks() as $chunk) {
                    if ($chunk instanceof $class) {
                        $chunks[] = $chunk;
                    }
                }
            }
        }

        return $chunks;
    }
}

class RconTestServer extends AbstractServerInstance
{
    public AbstractWorld $entryWorld;

    /** @var array<int, RconTestSocket> */
    protected array $clientSockets = [];

    public bool $shutdownRequested = false;

    public function __construct(string $password = 'test-secret', int $maxTries = 3, int $banDuration = 300, string $configFile = '', ?TickHandler $clock = null, ?Config $config = null, bool $activatePvpSettings = true)
    {
        $config ??= new Config([
            'sv_name'              => 'RCON test',
            'sv_rcon_password'     => $password,
            'sv_rcon_max_tries'    => $maxTries,
            'sv_rcon_ban_duration' => (int) floor($banDuration / 60),
        ]);

        if ($configFile !== '') {
            $config->loadFile($configFile);
        }

        parent::__construct($clock ?? new TickHandler, $config, $activatePvpSettings);
        $this->bootConfiguredWorlds();
        $this->entryWorld = $this->worlds[0] ?? $this->addWorld('dm');
    }

    public function addWorld(string $identifier, string $map = 'dm1'): AbstractWorld
    {
        return $this->addWorldFromConfig(new WorldConfig($identifier, $map, 64, 0));
    }

    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        $world = new \TestWorldWithController(
            $this->tickHandler,
            new Map(__DIR__.'/../'.$config->settings->getString('sv_world_map').'.map'),
            new \TestGameController($this->tickHandler),
            $this,
            slotsLimit: $config->slotsLimit,
            config: $config->settings,
            spectatorSlots: $config->spectatorSlots,
        );
        $world->identifier = $config->identifier;

        return $world;
    }

    public function addClient(?AbstractWorld $world = null, string $address = '127.0.0.1', int $port = 8300, int $state = ConnectionSlot::STATE_INGAME): ConnectionSlot
    {
        $world ??= $this->entryWorld;
        $socket     = new RconTestSocket;
        $connection = $this->connectionHandler->startNew($socket, $world);
        if ($connection === false) {
            throw new \RuntimeException('No free RCON test slot');
        }
        $connection->init($address, $port);
        $connection->state                               = $state;
        $this->clientSockets[spl_object_id($connection)] = $socket;
        if ($state === ConnectionSlot::STATE_INGAME) {
            $world->addTee($connection->playerTee());
        }
        $socket->packets = [];

        return $connection;
    }

    public function socket(ConnectionSlot $connection): RconTestSocket
    {
        return $this->clientSockets[spl_object_id($connection)];
    }

    public function send(ConnectionSlot $connection, AbstractChunk ...$chunks): void
    {
        $sequence = $connection->ack;
        foreach ($chunks as $chunk) {
            if (($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) && $chunk->getSequence() === -1) {
                $chunk->setSequence(++$sequence);
            }
        }
        $this->receive(new DefaultPacket($chunks, ack: $connection->sequence), $this->socket($connection), $connection->destinationAddress, $connection->destinationPort);
    }

    public function connect(string $address, int $port): RconTestSocket
    {
        $socket = new RconTestSocket;
        $this->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), $socket, $address, $port);

        return $socket;
    }

    protected function receive(AbstractPacket $packet, RconTestSocket $socket, string $address, int $port): void
    {
        $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
        if ($decoded === false) {
            throw new \RuntimeException('Test emitted an invalid RCON packet');
        }
        $this->onPacket($decoded, ['address' => $address, 'port' => $port], $socket);
    }

    public function tick(): void
    {
        $this->doTick();
    }

    public function shutdown(): void
    {
        $this->shutdownRequested = true;
        parent::shutdown();
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->entryWorld;
    }
}
