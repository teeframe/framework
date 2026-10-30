<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class TransportTestClock extends TickHandler
{
    public int $now = 1_000_000_000_000;

    public function getTime(): int
    {
        return $this->now;
    }
}

class TransportTestSocket extends AbstractSocket
{
    /** @var list<AbstractPacket> */
    public array $packets = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($send_data));
        $packet = PacketDecoder::decodeFromRaw($send_data);
        if ($packet === false) {
            throw new RuntimeException('Server emitted an invalid transport packet');
        }
        $this->packets[] = $packet;

        return true;
    }

    /** @return AbstractChunk[] */
    public function chunks(): array
    {
        $chunks = [];
        foreach ($this->packets as $packet) {
            if ($packet instanceof DefaultPacket) {
                $chunks = [...$chunks, ...$packet->getChunks()];
            }
        }

        return $chunks;
    }

    /** @return list<int> */
    public function emoticons(): array
    {
        return array_values(array_map(
            fn (SvEmoticonChunk $chunk): int => $chunk->emoticon,
            array_filter($this->chunks(), fn (AbstractChunk $chunk): bool => $chunk instanceof SvEmoticonChunk),
        ));
    }

    /** @return list<ControlMessage> */
    public function controls(): array
    {
        return array_values(array_filter($this->packets, fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage));
    }
}

class TransportTestServer extends AbstractServerInstance
{
    public TransportTestClock $clock;
    public TransportTestSocket $socket;
    public TestWorldWithController $entryWorld;

    public function __construct(int $slotsLimit = 4)
    {
        $this->clock  = new TransportTestClock;
        $this->socket = new TransportTestSocket;
        parent::__construct($this->clock, 'Transport test');
        $this->entryWorld = $this->addWorld($slotsLimit);
    }

    public function addWorld(int $slotsLimit = 4): TestWorldWithController
    {
        $world          = new TestWorldWithController($this->clock, new Map, new TestGameController($this->clock), $this, $slotsLimit);
        $this->worlds[] = $world;

        return $world;
    }

    public function connect(int $port = 8300): ConnectionSlot
    {
        $this->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), $port);
        $slot = $this->connectionHandler->tryToMatch(['address' => '127.0.0.1', 'port' => $port]);
        if ($slot === false) {
            throw new RuntimeException('Test connection was not accepted');
        }

        return $slot;
    }

    public function addPlayer(?AbstractWorld $world = null, int $port = 8300): ConnectionSlot
    {
        $world ??= $this->entryWorld;
        $slot = $this->connectionHandler->startNew($this->socket, $world);
        if ($slot === false) {
            throw new RuntimeException('No free test connection slot');
        }
        $slot->init('127.0.0.1', $port);
        $slot->state = ConnectionSlot::STATE_INGAME;
        $world->addTee($slot->playerTee());

        return $slot;
    }

    public function receive(AbstractPacket $packet, int $port = 8300): void
    {
        $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
        if ($decoded === false) {
            throw new RuntimeException('Test emitted an invalid transport packet');
        }
        $this->onPacket($decoded, ['address' => '127.0.0.1', 'port' => $port], $this->socket);
    }

    public function tick(): void
    {
        $this->doTick();
    }

    public function connectionCount(): int
    {
        return count($this->connectionHandler->getConnections());
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->entryWorld;
    }
}

test('duplicate vital gameplay messages execute only once', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $server->socket->packets = [];
    $packet                  = new DefaultPacket([(new ClEmoticonChunk(1))->setSequence(1)], ack: $slot->sequence);

    $server->receive($packet);
    $server->receive($packet);

    expect($server->socket->emoticons())->toBe([1]);
    expect($slot->ack)->toBe(1);
    expect($slot->getReceivedChunks())->toBeEmpty();
});

test('out-of-order vital chunks wait for the gap and do not block later in-order chunks', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([
        (new ClEmoticonChunk(2))->setSequence(2),
        (new ClEmoticonChunk(1))->setSequence(1),
        (new ClEmoticonChunk(1))->setSequence(1),
        (new ClEmoticonChunk(2))->setSequence(2),
    ], ack: $slot->sequence));

    expect($server->socket->emoticons())->toBe([1, 2]);
    expect($slot->ack)->toBe(2);
    $controls = $server->socket->controls();
    expect($controls)->toHaveCount(1);
    expect($controls[0]->isResend())->toBeTrue();
    expect($controls[0]->getAck())->toBe(0);
});

test('vital receive sequencing handles wraparound and ignores old chunks', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $slot->ack               = 1022;
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([
        (new ClEmoticonChunk(1))->setSequence(1023),
        (new ClEmoticonChunk(2))->setSequence(0),
        (new ClEmoticonChunk(1))->setSequence(1023),
        (new ClEmoticonChunk(3))->setSequence(1),
    ], ack: $slot->sequence));

    expect($server->socket->emoticons())->toBe([1, 2, 3]);
    expect($slot->ack)->toBe(1);
    expect($server->socket->controls())->toBeEmpty();
});

test('non-vital chunks remain deliverable while a vital sequence is missing', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([
        (new ClEmoticonChunk(2))->setSequence(2),
        (new ClEmoticonChunk(3))->setFlags(0),
    ], ack: $slot->sequence));

    expect($server->socket->emoticons())->toBe([3]);
    expect($slot->ack)->toBe(0);
    expect($slot->getReceivedChunks())->toHaveCount(1);
    expect($slot->getReceivedChunks()[0]->getFlags())->toBe(0);
});

test('unsupported vital chunks advance the sequence without replaying or blocking supported chunks', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([
        (new UnsupportedChunk(127, NetworkBase::CHUNK_FLAG_VITAL, false))->setSequence(1),
        (new ClEmoticonChunk(4))->setSequence(2),
    ], ack: $slot->sequence));

    expect($slot->ack)->toBe(2);
    expect($server->socket->emoticons())->toBe([4]);
});

test('resend requests are throttled without delivering the rejected chunks', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->addPlayer();
    $server->socket->packets = [];
    $packet                  = new DefaultPacket([(new ClEmoticonChunk(3))->setSequence(3)], ack: $slot->sequence);
    $server->receive($packet);
    $server->receive($packet);
    expect($server->socket->controls())->toHaveCount(1);

    $server->clock->now += 1_000_000_000;
    $server->receive($packet);
    expect($server->socket->controls())->toHaveCount(2);
    expect($server->socket->emoticons())->toBeEmpty();
    expect($slot->ack)->toBe(0);
});

test('handshake dispatch applies the same vital sequence filtering as gameplay', function () {
    $server = new TransportTestServer;
    $slot   = $server->connect();
    $server->receive(new DefaultPacket([(new InfoChunk('0.6 626fce9a778df4d4'))->setSequence(2)], ack: $slot->sequence));
    expect($slot->state)->toBe(ConnectionSlot::STATE_CONNECTING);
    expect($slot->ack)->toBe(0);

    $server->receive(new DefaultPacket([(new InfoChunk('0.6 626fce9a778df4d4'))->setSequence(1)], ack: $slot->sequence));
    expect($slot->state)->toBe(ConnectionSlot::STATE_LOADING);
    $server->receive(new DefaultPacket([(new ReadyChunk)->setSequence(2)], ack: $slot->sequence));
    expect($slot->state)->toBe(ConnectionSlot::STATE_READY);

    $start = (new ClStartInfoChunk('Player', '', 0, 'default', false, 0, 0))->setSequence(3);
    $server->receive(new DefaultPacket([$start], ack: $slot->sequence));
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([$start], ack: $slot->sequence));

    expect($slot->ack)->toBe(3);
    expect($server->socket->packets)->toBeEmpty();
});

test('invalid transport ACKs do not deliver chunks or refresh receive time', function () {
    $server       = new TransportTestServer;
    $slot         = $server->addPlayer();
    $lastReceived = $slot->lastRecvTime;
    $server->clock->now += 5_000_000_000;
    $server->socket->packets = [];
    $server->receive(new DefaultPacket([(new ClEmoticonChunk(1))->setSequence(1)], ack: $slot->sequence + 1));

    expect($slot->lastRecvTime)->toBe($lastReceived);
    expect($slot->ack)->toBe(0);
    expect($slot->getReceivedChunks())->toBeEmpty();
    expect($server->socket->packets)->toBeEmpty();
});

test('connection maintenance expires silent clients in every active state', function (int $state) {
    $server      = new TransportTestServer(1);
    $slot        = $state === ConnectionSlot::STATE_INGAME ? $server->addPlayer() : $server->connect();
    $slot->state = $state;
    $server->clock->now += 10_000_000_000;
    $server->tick();
    expect($slot->state)->toBe($state);

    $server->socket->packets = [];
    $server->clock->now++;
    $server->tick();

    expect($slot->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->connectionCount())->toBe(0);
    expect($server->entryWorld->getTees())->toBeEmpty();
    $controls = $server->socket->controls();
    expect($controls)->toHaveCount(1);
    expect($controls[0]->getControlMessage())->toBe(NetworkMessages::CONTROL_CLOSE);
    expect($controls[0]->getControlMessageExtra())->toBe("Timeout\0");
    expect($server->connect())->not->toBe($slot);
})->with([
    'connecting' => ConnectionSlot::STATE_CONNECTING,
    'loading'    => ConnectionSlot::STATE_LOADING,
    'ready'      => ConnectionSlot::STATE_READY,
    'in game'    => ConnectionSlot::STATE_INGAME,
]);

test('receive activity keeps its own connection alive without protecting another world', function () {
    $server     = new TransportTestServer;
    $expired    = $server->addPlayer();
    $otherWorld = $server->addWorld();
    $active     = $server->addPlayer($otherWorld, 8301);
    $server->clock->now += 10_000_000_001;
    $server->receive(new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE, ack: $active->sequence), 8301);
    $server->tick();

    expect($expired->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($active->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($server->connectionCount())->toBe(1);
    expect($server->entryWorld->getTees())->toBeEmpty();
    expect($otherWorld->getTees())->toBe([$active->playerTee()]);
});

test('maintenance sends keep-alives only after the idle interval', function () {
    $server                  = new TransportTestServer;
    $slot                    = $server->connect();
    $server->socket->packets = [];
    $server->clock->now += 1_000_000_000;
    $server->tick();
    expect($server->socket->packets)->toBeEmpty();

    $server->clock->now++;
    $server->tick();
    $controls = $server->socket->controls();
    expect($controls)->toHaveCount(1);
    expect($controls[0]->getControlMessage())->toBe(NetworkMessages::CONTROL_KEEP_ALIVE);
    expect($slot->lastSendTime)->toBe($server->clock->now);
    $server->tick();
    expect($server->socket->controls())->toHaveCount(1);
});

test('maintenance flushes buffered chunks after half a second without an extra keep-alive', function () {
    $server = new TransportTestServer;
    $slot   = $server->connect();
    $slot->chunks()->add(new SvMotdChunk('Buffered'));
    $server->socket->packets = [];
    $server->clock->now += 500_000_000;
    $server->tick();
    expect($server->socket->packets)->toBeEmpty();

    $server->clock->now++;
    $server->tick();
    expect($server->socket->chunks())->toHaveCount(1);
    expect($server->socket->chunks()[0])->toBeInstanceOf(SvMotdChunk::class);
    expect($server->socket->controls())->toBeEmpty();
});

test('maintenance retransmits the oldest unacknowledged vital chunk after one second', function () {
    $server = new TransportTestServer;
    $slot   = $server->connect();
    $slot->chunks()->add(new SvMotdChunk('First'))->add(new SvMotdChunk('Second'))->send();
    $server->socket->packets = [];
    $server->clock->now += 1_000_000_000;
    $server->tick();
    expect($server->socket->packets)->toBeEmpty();

    $server->clock->now++;
    $server->tick();
    $chunks = $server->socket->chunks();
    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->getSequence())->toBe(1);
    expect($chunks[0]->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL | NetworkBase::CHUNK_FLAG_RESEND);
    expect($slot->sequence)->toBe(2);
    expect($server->socket->controls())->toBeEmpty();

    $server->receive(new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE, ack: 2));
    $server->socket->packets = [];
    $server->clock->now += 1_000_000_001;
    $server->tick();
    expect($server->socket->chunks())->toBeEmpty();
});

test('client-requested retransmissions preserve sequence numbers and do not request a resend back', function () {
    $server = new TransportTestServer;
    $slot   = $server->connect();
    $slot->chunks()->add(new SvMotdChunk('Reliable'))->send();
    $server->socket->packets = [];
    $server->receive(new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE, ack: 0, resend: true));

    expect($server->socket->packets)->toHaveCount(1);
    expect($server->socket->packets[0]->isResend())->toBeFalse();
    expect($server->socket->chunks()[0]->getSequence())->toBe(1);
    expect($server->socket->chunks()[0]->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL | NetworkBase::CHUNK_FLAG_RESEND);
    expect($slot->sequence)->toBe(1);
});

test('unacknowledged vital data times out even when the peer keeps sending and retries occur', function () {
    $server = new TransportTestServer;
    $slot   = $server->connect();
    $slot->chunks()->add(new SvMotdChunk('Never acknowledged'))->send();
    $server->clock->now += 9_000_000_000;
    $server->receive(new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE, ack: 0));
    $server->tick();
    expect($slot->state)->toBe(ConnectionSlot::STATE_CONNECTING);

    $server->socket->packets = [];
    $server->clock->now += 1_000_000_001;
    $server->receive(new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE, ack: 0));
    $server->tick();

    expect($slot->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->connectionCount())->toBe(0);
    $controls = $server->socket->controls();
    expect($controls)->toHaveCount(1);
    expect($controls[0]->getControlMessageExtra())->toBe("Too weak connection (not acked for 10 seconds)\0");
});

test('repeated CONNECT retries the accept without allocating a second handshake slot', function () {
    $server                  = new TransportTestServer(1);
    $slot                    = $server->connect();
    $server->socket->packets = [];
    $sameSlot                = $server->connect();

    expect($sameSlot)->toBe($slot);
    expect($server->connectionCount())->toBe(1);
    expect($slot->state)->toBe(ConnectionSlot::STATE_CONNECTING);
    $controls = $server->socket->controls();
    expect($controls)->toHaveCount(1);
    expect($controls[0]->getControlMessage())->toBe(NetworkMessages::CONTROL_CONNECT_ACCEPT);
});

test('CONNECT from an existing in-game endpoint cannot allocate or reject another slot', function () {
    $server                  = new TransportTestServer(1);
    $slot                    = $server->addPlayer();
    $tee                     = $slot->playerTee();
    $server->socket->packets = [];
    $server->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT, ack: $slot->sequence));

    expect($server->connectionCount())->toBe(1);
    expect($slot->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($slot->playerTee())->toBe($tee);
    expect($server->socket->packets)->toBeEmpty();
});
