<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class ServerInfoTestSocket extends AbstractSocket
{
    /** @var array<array{address: string, port: int, data: string}> */
    public array $sent = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($ip));
        assert(is_int($port));
        assert(is_string($send_data));
        $this->sent[] = ['address' => $ip, 'port' => $port, 'data' => $send_data];

        return true;
    }
}

class ServerInfoTestServer extends AbstractServerInstance
{
    public int $worldSelections = 0;

    public function __construct(string $name = 'Test server', string $password = '')
    {
        parent::__construct(new TickHandler, name: $name, password: $password);
    }

    public function addWorld(string $map = 'dm1', bool $teams = false, bool $ctf = false, int $spectatorSlots = 0, int $slotsLimit = 64): AbstractWorld
    {
        $controller     = new TestGameController($this->tickHandler, isTeamMode: $teams, isCaptureTheFlag: $ctf, spectatorSlots: $spectatorSlots);
        $world          = new TestWorldWithController($this->tickHandler, new Map(__DIR__.'/../'.$map.'.map'), $controller, $this, $slotsLimit);
        $this->worlds[] = $world;

        return $world;
    }

    public function addClient(AbstractWorld $world, AbstractSocket $socket, int $port, int $state = ConnectionSlot::STATE_INGAME): ConnectionSlot
    {
        $slot = $this->connectionHandler->startNew($socket, $world);
        if ($slot === false) {
            throw new RuntimeException('No free test connection slot');
        }
        $slot->init('127.0.0.1', $port);
        $slot->state = $state;
        if ($state === ConnectionSlot::STATE_INGAME) {
            $world->addTee($slot->playerTee());
        }

        return $slot;
    }

    public function receive(string $data, AbstractSocket $socket, int $port = 8300): void
    {
        $packet = PacketDecoder::decodeFromRaw($data);
        if ($packet !== false) {
            $this->onPacket($packet, ['address' => '127.0.0.1', 'port' => $port], $socket);
        }
    }

    public function connectionCount(): int
    {
        return count($this->connectionHandler->getConnections());
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        $this->worldSelections++;

        return $this->worlds[0];
    }
}

function serverInfoRequest(int $token = 42, int $extraToken = 0): string
{
    return 'xe'.pack('n', $extraToken)."\0\0".str_repeat("\xFF", 4).'gie3'.chr($token & 0xFF);
}

/** @return string[] */
function serverInfoFields(ServerInfoTestSocket $socket): array
{
    expect($socket->sent)->toHaveCount(1);
    $data = $socket->sent[0]['data'];
    expect(substr($data, 0, 14))->toBe(str_repeat("\xFF", 10).'iext');
    expect(substr($data, -1))->toBe("\0");

    return explode("\0", substr($data, 14, -1));
}

test('connectionless packets decode and encode with the six-byte 0.6 header', function () {
    $raw    = str_repeat("\xFF", 10).'gie3'.chr(255);
    $packet = PacketDecoder::decodeFromRaw($raw);
    expect($packet)->toBeInstanceOf(ConnectionlessPacket::class);
    assert($packet instanceof ConnectionlessPacket);
    expect($packet->getData())->toBe(str_repeat("\xFF", 4).'gie3'.chr(255));
    expect($packet->getFlags())->toBe(NetworkBase::PACKET_FLAG_TYPE_CONNECTION_LESS);
    expect($packet->getAck())->toBe(0);
    expect($packet->getNumChunks())->toBe(0);
    expect($packet->isResend())->toBeFalse();
    expect($packet->encodeToSend())->toBe($raw);
});

test('truncated connectionless headers are rejected', function (int $size) {
    expect(PacketDecoder::decodeFromRaw(str_repeat("\xFF", $size)))->toBeFalse();
})->with([3, 4, 5]);

test('server info answers browser queries with decimal string fields and echoed tokens', function (int $token) {
    $server = new ServerInfoTestServer;
    $world  = $server->addWorld();
    $socket = new ServerInfoTestSocket;

    $server->receive(serverInfoRequest($token), $socket);

    expect(serverInfoFields($socket))->toBe([
        (string) $token, '0.6 TeeFrame', 'Test server', 'dm1',
        (string) $world->getMap()->getCrc(), (string) $world->getMap()->getSize(),
        'DM', '0', '0', '64', '0', '64', '',
    ]);
    expect($socket->sent[0]['address'])->toBe('127.0.0.1');
    expect($socket->sent[0]['port'])->toBe(8300);
    expect($server->connectionCount())->toBe(0);
})->with([0, 42, 255]);

test('server info includes players spectators and loading clients but not closed connections', function () {
    $server                       = new ServerInfoTestServer(password: 'secret');
    $world                        = $server->addWorld();
    $socket                       = new ServerInfoTestSocket;
    $player                       = $server->addClient($world, $socket, 8300);
    $player->playerTee()->name    = 'Player';
    $player->playerTee()->clan    = 'Clan';
    $player->playerTee()->country = 76;
    $player->playerTee()->score   = -3;
    $player->peerAck              = 7;
    $player->lastRecvTime         = 123;
    $spectator                    = $server->addClient($world, $socket, 8301);
    $spectator->playerTee()->name = 'Spectator';
    $spectator->playerTee()->team = GameConstants::TEAM_SPECTATORS;
    $server->addClient($world, $socket, 8302, ConnectionSlot::STATE_LOADING);
    $server->addClient($world, $socket, 8303, ConnectionSlot::STATE_CLOSED);
    $socket->sent = [];

    $server->receive(serverInfoRequest(), $socket);

    expect(serverInfoFields($socket))->toBe([
        '42', '0.6 TeeFrame', 'Test server', 'dm1',
        (string) $world->getMap()->getCrc(), (string) $world->getMap()->getSize(),
        'DM', '1', '2', '64', '3', '64', '',
        'Player', 'Clan', '76', '-3', '1', '',
        'Spectator', '', '0', '0', '0', '',
        '(connecting)', '', '0', '0', '1', '',
    ]);
    expect($player->peerAck)->toBe(7);
    expect($player->lastRecvTime)->toBe(123);
    expect($server->worldSelections)->toBe(0);
});

test('server info selects the connected clients world without leaking another worlds clients', function (int $state) {
    $server                    = new ServerInfoTestServer;
    $firstWorld                = $server->addWorld();
    $secondWorld               = $server->addWorld('ctf1', teams: true, ctf: true);
    $socket                    = new ServerInfoTestSocket;
    $first                     = $server->addClient($firstWorld, $socket, 8300);
    $first->playerTee()->name  = 'First world';
    $second                    = $server->addClient($secondWorld, $socket, 8301, $state);
    $second->playerTee()->name = 'Second world';
    $socket->sent              = [];

    $server->receive(serverInfoRequest(), $socket, 8301);

    $fields = serverInfoFields($socket);
    expect($fields[3])->toBe('ctf1');
    expect($fields[6])->toBe('CTF');
    expect($fields[10])->toBe('1');
    expect($fields[13])->toBe($state === ConnectionSlot::STATE_INGAME ? 'Second world' : '(connecting)');
    expect($fields)->not()->toContain('First world');
    expect($server->worldSelections)->toBe(0);
    expect($second->state)->toBe($state);

    $socket->sent = [];
    $server->receive(serverInfoRequest(), $socket, 8400);
    $fields = serverInfoFields($socket);
    expect($fields[3])->toBe('MultiWorld');
    expect($fields[6])->toBe('MW');
    expect($fields[10])->toBe('2');
    expect($fields[13])->toBe('First world');
    expect($fields[19])->toBe($state === ConnectionSlot::STATE_INGAME ? 'Second world' : '(connecting)');
    expect($server->connectionCount())->toBe(2);
})->with([ConnectionSlot::STATE_LOADING, ConnectionSlot::STATE_INGAME]);

test('server info reports team mode and reserved spectator capacity', function () {
    $server = new ServerInfoTestServer;
    $server->addWorld(teams: true, spectatorSlots: 2, slotsLimit: 8);
    $socket = new ServerInfoTestSocket;

    $server->receive(serverInfoRequest(), $socket);

    expect(array_slice(serverInfoFields($socket), 6))->toBe(['TDM', '0', '0', '6', '0', '8', '']);
});

test('non-extended server info requests are ignored without a legacy fallback', function () {
    $server = new ServerInfoTestServer;
    $server->addWorld();
    $socket = new ServerInfoTestSocket;

    $server->receive(str_repeat("\xFF", 10).'gie3'.chr(42), $socket);

    expect($socket->sent)->toBeEmpty();
    expect($server->connectionCount())->toBe(0);
    expect($server->worldSelections)->toBe(0);
});

test('extended connectionless requests preserve their extra token', function (int $extraToken) {
    $raw    = serverInfoRequest(255, $extraToken);
    $packet = PacketDecoder::decodeFromRaw($raw);
    expect($packet)->toBeInstanceOf(ConnectionlessPacket::class);
    assert($packet instanceof ConnectionlessPacket);
    expect($packet->getExtraToken())->toBe($extraToken);
    expect($packet->getData())->toBe(str_repeat("\xFF", 4).'gie3'.chr(255));
    expect($packet->encodeToSend())->toBe($raw);
})->with([0, 0x1234, 0xFFFF]);

test('extended server info advertises the actual capacity and combines the full request token', function (int $extraToken) {
    $server = new ServerInfoTestServer;
    $world  = $server->addWorld(spectatorSlots: 4);
    $socket = new ServerInfoTestSocket;

    $server->receive(serverInfoRequest(255, $extraToken), $socket);

    expect(serverInfoFields($socket))->toBe([
        (string) (($extraToken << 8) | 255), '0.6 TeeFrame', 'Test server', 'dm1',
        (string) $world->getMap()->getCrc(), (string) $world->getMap()->getSize(),
        'DM', '0', '0', '60', '0', '64', '',
    ]);
    expect($server->connectionCount())->toBe(0);
})->with([0, 0x1234, 0xFFFF]);

test('extended server info sends every client without a sixteen-player cap', function (int $clientCount) {
    $server          = new ServerInfoTestServer;
    $world           = $server->addWorld(spectatorSlots: 4);
    $socket          = new ServerInfoTestSocket;
    $expectedClients = [];
    $numPlayers      = 0;
    for ($i = 0; $i < $clientCount; $i++) {
        $slot         = $server->addClient($world, $socket, 8300 + $i);
        $tee          = $slot->playerTee();
        $tee->name    = sprintf('Player%010d', $i);
        $tee->clan    = str_repeat('C', 12);
        $tee->country = 76;
        $tee->score   = $i;
        $isPlayer     = $i % 4 !== 0;
        $tee->team    = $isPlayer ? GameConstants::TEAM_RED : GameConstants::TEAM_SPECTATORS;
        $numPlayers += (int) $isPlayer;
        $expectedClients[] = [$tee->name, $tee->clan, '76', (string) $i, (string) (int) $isPlayer, ''];
    }
    $socket->sent = [];

    $server->receive(serverInfoRequest(255, 0xFFFF), $socket);

    expect($socket->sent)->not()->toBeEmpty();
    if ($clientCount === 64) {
        expect(count($socket->sent))->toBeGreaterThan(1);
    }
    $receivedClients = [];
    foreach ($socket->sent as $packetNo => $packet) {
        expect($packet['address'])->toBe('127.0.0.1');
        expect($packet['port'])->toBe(8300);
        expect(strlen($packet['data']))->toBeLessThanOrEqual(1400);
        expect(substr($packet['data'], -1))->toBe("\0");
        $fields = explode("\0", substr($packet['data'], 14, -1));
        expect($fields[0])->toBe('16777215');
        if ($packetNo === 0) {
            expect(substr($packet['data'], 0, 14))->toBe(str_repeat("\xFF", 10).'iext');
            expect(array_slice($fields, 8, 5))->toBe([(string) $numPlayers, '60', (string) $clientCount, '64', '']);
            $clientFields = array_slice($fields, 13);
        } else {
            expect(substr($packet['data'], 0, 14))->toBe(str_repeat("\xFF", 10).'iex+');
            expect(array_slice($fields, 1, 2))->toBe([(string) $packetNo, '']);
            $clientFields = array_slice($fields, 3);
        }
        expect(count($clientFields) % 6)->toBe(0);
        array_push($receivedClients, ...array_chunk($clientFields, 6));
    }
    expect($receivedClients)->toBe($expectedClients);
    expect($server->connectionCount())->toBe($clientCount);
})->with([17, 64]);

test('extended server info reports all active players and the configured capacity', function (int $capacity) {
    $server = new ServerInfoTestServer;
    $world  = $server->addWorld(slotsLimit: $capacity);
    $socket = new ServerInfoTestSocket;
    for ($i = 0; $i < $capacity; $i++) {
        $slot                    = $server->addClient($world, $socket, 8300 + $i);
        $slot->playerTee()->name = 'P'.$i;
    }
    $socket->sent = [];

    $server->receive(serverInfoRequest(42, 0), $socket);

    $fields = serverInfoFields($socket);
    expect(array_slice($fields, 8, 4))->toBe(array_fill(0, 4, (string) $capacity));
    expect($fields)->toHaveCount(13 + $capacity * 6);
    expect($server->connectionCount())->toBe($capacity);
})->with([32, 64]);

test('extended server info retains world isolation', function () {
    $server                    = new ServerInfoTestServer;
    $firstWorld                = $server->addWorld();
    $secondWorld               = $server->addWorld('ctf1', teams: true, ctf: true);
    $socket                    = new ServerInfoTestSocket;
    $first                     = $server->addClient($firstWorld, $socket, 8300);
    $first->playerTee()->name  = 'First world';
    $second                    = $server->addClient($secondWorld, $socket, 8301);
    $second->playerTee()->name = 'Second world';
    $socket->sent              = [];

    $server->receive(serverInfoRequest(42, 0x1234), $socket, 8301);

    $fields = serverInfoFields($socket);
    expect($fields[3])->toBe('ctf1');
    expect($fields[6])->toBe('CTF');
    expect(array_slice($fields, 10, 2))->toBe(['1', '64']);
    expect($fields[13])->toBe('Second world');
    expect($fields)->not()->toContain('First world');
    expect($server->worldSelections)->toBe(0);
});

test('browser info merges worlds and sums their player and client capacities', function () {
    $server                                                    = new ServerInfoTestServer;
    $dm                                                        = $server->addWorld(slotsLimit: 32, spectatorSlots: 2);
    $ctf                                                       = $server->addWorld('ctf1', teams: true, ctf: true, slotsLimit: 64, spectatorSlots: 4);
    $socket                                                    = new ServerInfoTestSocket;
    $server->addClient($dm, $socket, 8300)->playerTee()->name  = 'DM player';
    $server->addClient($ctf, $socket, 8301)->playerTee()->name = 'CTF player';
    $spectator                                                 = $server->addClient($ctf, $socket, 8302)->playerTee();
    $spectator->name                                           = 'CTF spectator';
    $spectator->team                                           = GameConstants::TEAM_SPECTATORS;
    $server->addClient($dm, $socket, 8303, ConnectionSlot::STATE_LOADING);
    $server->addClient($ctf, $socket, 8304, ConnectionSlot::STATE_CLOSED);
    $socket->sent = [];

    $server->receive(serverInfoRequest(), $socket, 9000);

    $fields = serverInfoFields($socket);
    expect(array_slice($fields, 3, 10))->toBe(['MultiWorld', '0', '0', 'MW', '0', '3', '90', '4', '96', '']);
    expect(array_column(array_chunk(array_slice($fields, 13), 6), 0))->toBe([
        'DM player', 'CTF player', 'CTF spectator', '(connecting)',
    ]);
    expect($server->worldSelections)->toBe(0);
    expect($server->connectionCount())->toBe(5);

    $socket->sent = [];
    $server->receive(serverInfoRequest(), $socket, 8301);
    $fields = serverInfoFields($socket);
    expect($fields[3])->toBe('ctf1');
    expect($fields[6])->toBe('CTF');
    expect(array_slice($fields, 8, 4))->toBe(['1', '60', '2', '64']);
    expect($fields)->not()->toContain('DM player', '(connecting)');
});

test('connected queries use each worlds gamemode and slot limit including after switching worlds', function () {
    $server                                                    = new ServerInfoTestServer;
    $dm                                                        = $server->addWorld(slotsLimit: 32, spectatorSlots: 2);
    $ctf                                                       = $server->addWorld('ctf1', teams: true, ctf: true, slotsLimit: 48, spectatorSlots: 4);
    $socket                                                    = new ServerInfoTestSocket;
    $client                                                    = $server->addClient($dm, $socket, 8300);
    $client->playerTee()->name                                 = 'Traveler';
    $server->addClient($ctf, $socket, 8301)->playerTee()->name = 'CTF player';
    $socket->sent                                              = [];

    $server->receive(serverInfoRequest(), $socket, 8300);

    $fields = serverInfoFields($socket);
    expect(array_slice($fields, 3, 4))->toBe([
        'dm1', (string) $dm->getMap()->getCrc(), (string) $dm->getMap()->getSize(), 'DM',
    ]);
    expect(array_slice($fields, 8, 4))->toBe(['1', '30', '1', '32']);
    expect($fields)->not()->toContain('MultiWorld', 'MW', 'CTF player');

    expect($server->changeWorld($client->playerTee(), $ctf))->toBeTrue();
    expect($client->state)->toBe(ConnectionSlot::STATE_LOADING);
    $socket->sent = [];
    $server->receive(serverInfoRequest(), $socket, 8300);

    $fields = serverInfoFields($socket);
    expect(array_slice($fields, 3, 4))->toBe([
        'ctf1', (string) $ctf->getMap()->getCrc(), (string) $ctf->getMap()->getSize(), 'CTF',
    ]);
    expect(array_slice($fields, 8, 4))->toBe(['2', '44', '2', '48']);
    expect($fields)->not()->toContain('MultiWorld', 'MW');

    $client->state = ConnectionSlot::STATE_INGAME;
    $ctf->addTee($client->playerTee());
    $socket->sent = [];
    $server->receive(serverInfoRequest(), $socket, 8300);
    $fields = serverInfoFields($socket);
    expect($fields[3])->toBe('ctf1');
    expect($fields[6])->toBe('CTF');
    expect(array_slice($fields, 8, 4))->toBe(['2', '44', '2', '48']);
    expect($fields)->toContain('Traveler', 'CTF player');
});

test('browser capacity is derived only from the configured world limits', function () {
    $server = new ServerInfoTestServer;
    $server->addWorld(slotsLimit: 32);
    $server->addWorld('ctf1', teams: true, ctf: true, slotsLimit: 64);
    $socket = new ServerInfoTestSocket;

    $server->receive(serverInfoRequest(), $socket);

    expect(array_slice(serverInfoFields($socket), 8, 4))->toBe(['0', '96', '0', '96']);

    $server->addWorld(slotsLimit: 16, spectatorSlots: 2);
    $socket->sent = [];
    $server->receive(serverInfoRequest(), $socket);

    expect(array_slice(serverInfoFields($socket), 8, 4))->toBe(['0', '110', '0', '112']);
});

test('browser info sends all 128 clients across worlds with complete continuation records', function () {
    $server        = new ServerInfoTestServer;
    $worlds        = [$server->addWorld(), $server->addWorld('ctf1', teams: true, ctf: true)];
    $socket        = new ServerInfoTestSocket;
    $expectedNames = [];
    for ($i = 0; $i < 128; $i++) {
        $slot                    = $server->addClient($worlds[intdiv($i, 64)], $socket, 8300 + $i);
        $slot->playerTee()->name = sprintf('Player%010d', $i);
        $slot->playerTee()->clan = str_repeat('C', 12);
        $expectedNames[]         = $slot->playerTee()->name;
    }
    $socket->sent = [];

    $server->receive(serverInfoRequest(42, 0x1234), $socket, 9000);

    expect(count($socket->sent))->toBeGreaterThan(1);
    $names = [];
    foreach ($socket->sent as $packetNo => $packet) {
        expect(strlen($packet['data']))->toBeLessThanOrEqual(1400);
        $fields = explode("\0", substr($packet['data'], 14, -1));
        expect($fields[0])->toBe('1193002');
        if ($packetNo === 0) {
            expect(array_slice($fields, 8, 4))->toBe(['128', '128', '128', '128']);
            $clients = array_slice($fields, 13);
        } else {
            expect(substr($packet['data'], 0, 14))->toBe(str_repeat("\xFF", 10).'iex+');
            expect($fields[1])->toBe((string) $packetNo);
            $clients = array_slice($fields, 3);
        }
        expect(count($clients) % 6)->toBe(0);
        array_push($names, ...array_column(array_chunk($clients, 6), 0));
    }
    expect($names)->toBe($expectedNames);
});

test('server info limits strings and prevents embedded nulls from adding fields', function () {
    $server                  = new ServerInfoTestServer(name: str_repeat('N', 400));
    $world                   = $server->addWorld();
    $socket                  = new ServerInfoTestSocket;
    $slot                    = $server->addClient($world, $socket, 8300);
    $slot->playerTee()->name = "Name\0injected";
    $slot->playerTee()->clan = str_repeat('C', 50);
    $socket->sent            = [];

    $server->receive(serverInfoRequest(), $socket);

    $fields = serverInfoFields($socket);
    expect($fields)->toHaveCount(19);
    expect($fields[2])->toBe(str_repeat('N', 256));
    expect($fields[13])->toBe('Name');
    expect($fields[14])->toBe(str_repeat('C', 12));
});

test('unknown and malformed connectionless requests are ignored without allocating connections', function (string $payload) {
    $server = new ServerInfoTestServer;
    $server->addWorld();
    $socket = new ServerInfoTestSocket;

    $server->receive("xe\0\0\0\0".$payload, $socket);

    expect($socket->sent)->toBeEmpty();
    expect($server->connectionCount())->toBe(0);
    expect($server->worldSelections)->toBe(0);
})->with([
    'empty'             => '',
    'unknown'           => str_repeat("\xFF", 4).'other',
    'missing token'     => str_repeat("\xFF", 4).'gie3',
    'extra data'        => str_repeat("\xFF", 4).'gie3'.chr(42).'extra',
    'wrong signature'   => 'gie3'.chr(42),
    'unsolicited reply' => str_repeat("\xFF", 4).'iext'.chr(42),
]);
