<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine\System;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Console;
use TeeFrame\Server\Master\MasterServer;
use TeeFrame\Server\Master\MasterServerHttpClient;
use TeeFrame\Server\Master\MasterServerRegistration;
use TeeFrame\Server\ServerInfoPacketBuilder;
use TeeFrame\Server\Sockets\AbstractSocket;

class RegistrationTestClock extends TickHandler
{
    public int $now = 0;

    public function getTime(): int
    {
        return $this->now;
    }
}

class RegistrationTestHttpClient extends MasterServerHttpClient
{
    /** @var list<array{url: string, family: int, headers: array<string, string>, body: string, timeout: float, localAddress: string}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}|Throwable|Closure(): array{status: int, body: string}> */
    public array $responses = [];

    public function post(string $url, int $family, array $headers, string $body, float $timeout = 5.0, string $localAddress = ''): array
    {
        $this->requests[] = compact('url', 'family', 'headers', 'body', 'timeout', 'localAddress');
        $response         = array_shift($this->responses) ?? ['status' => 200, 'body' => '{"status":"success"}'];
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response instanceof Closure ? $response() : $response;
    }
}

class RegistrationTestSocket extends AbstractSocket
{
    public int $shutdowns = 0;

    public function __construct(string $host = '0.0.0.0', int $port = 8303)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function shutdown(): bool
    {
        $this->shutdowns++;

        return true;
    }

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        return true;
    }
}

class RegistrationTestServer extends AbstractServerInstance
{
    public RegistrationTestHttpClient $httpClient;
    public RegistrationTestClock $clock;

    public function __construct(bool $registerOnMasterServer = false)
    {
        $this->clock      = new RegistrationTestClock;
        $this->httpClient = new RegistrationTestHttpClient;
        parent::__construct($this->clock, new Config(['sv_name' => 'Public server', 'sv_password' => 'secret', 'sv_register_on_master_server' => (int) $registerOnMasterServer]));
    }

    public function addWorld(string $map = 'dm1', int $slotsLimit = 12, int $spectatorSlots = 0): AbstractWorld
    {
        $controller = new TestGameController($this->clock);
        $world      = new TestWorldWithController($this->clock, new Map(__DIR__.'/../'.$map.'.map'), $controller, $this, $slotsLimit, config: new Config([
            'sv_world_game_type' => $map === 'ctf1' ? 'ctf' : 'dm',
        ]), spectatorSlots: $spectatorSlots);
        $this->worlds[] = $world;

        return $world;
    }

    public function addClient(AbstractWorld $world, RegistrationTestSocket $socket, int $port, int $state = ConnectionSlot::STATE_INGAME): ConnectionSlot
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

    public function initialize(RegistrationTestSocket ...$sockets): void
    {
        $this->sockets = $sockets;
        $this->bootMasterServerRegistration();
    }

    public function updateRegistration(): void
    {
        $this->masterServer?->update(
            $this->worlds,
            $this->connectionHandler->getConnections(),
            $this->getName(),
            $this->getConfig()->getString('sv_password') !== '',
        );
    }

    public function tick(): void
    {
        $this->doTick();
    }

    public function receive(ConnectionlessPacket $packet, RegistrationTestSocket $socket): void
    {
        $this->onPacket($packet, ['address' => '127.0.0.1', 'port' => 9999], $socket);
    }

    protected function createMasterServer(): MasterServer
    {
        return new MasterServer($this->clock, $this->httpClient);
    }

    protected function boot(): void {}
}

/** @return array{RegistrationTestClock, RegistrationTestHttpClient, MasterServerRegistration} */
function registrationFixture(int $family = AF_INET): array
{
    $clock        = new RegistrationTestClock;
    $http         = new RegistrationTestHttpClient;
    $registration = new MasterServerRegistration($clock, 8303, $family, httpClient: $http);

    return [$clock, $http, $registration];
}

function registrationChallenge(string $secret, string $token = 'master-token'): ConnectionlessPacket
{
    $raw    = (new ConnectionlessPacket(NetworkMessages::SERVERBROWSE_CHALLENGE.$secret."\0".$token."\0"))->encodeToSend();
    $packet = PacketDecoder::decodeFromRaw($raw);
    if (! $packet instanceof ConnectionlessPacket) {
        throw new RuntimeException('Could not decode the registration challenge fixture');
    }

    return $packet;
}

test('registration waits for server info and sends only the selected 0.6 address family', function (int $family, string $protocol) {
    [, $http, $registration] = registrationFixture($family);
    $registration->update();
    expect($http->requests)->toBe([]);

    $registration->update('{"name":"Public server"}');
    expect($http->requests)->toHaveCount(1);
    $request = $http->requests[0];
    expect($request['url'])->toBe(MasterServerRegistration::URL);
    expect($request['family'])->toBe($family);
    expect($request['body'])->toBe('{"name":"Public server"}');
    expect($request['headers']['Address'])->toBe('tw-0.6+udp://connecting-address.invalid:8303');
    expect($request['headers']['Secret'])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/');
    expect($request['headers']['Challenge-Secret'])->toEndWith(':'.$protocol);
    expect(explode(':', $request['headers']['Challenge-Secret'])[0])->not->toBe($request['headers']['Secret']);
    expect($request['headers']['Info-Serial'])->toBe('0');
    expect($request['headers'])->not->toHaveKeys(['Challenge-Token', 'Connless-Token']);
})->with([[AF_INET, 'tw0.6/ipv4'], [AF_INET6, 'tw0.6/ipv6']]);

test('registration refreshes every fifteen seconds without resending accepted server info', function () {
    [$clock, $http, $registration] = registrationFixture();
    $registration->update('{"name":"Test"}');
    $clock->now = 14_999_999_999;
    $registration->update();
    expect($http->requests)->toHaveCount(1);

    $clock->now++;
    $registration->update();
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['body'])->toBe('');
    expect($http->requests[1]['headers']['Info-Serial'])->toBe('0');
    expect($http->requests[1]['headers']['Secret'])->toBe($http->requests[0]['headers']['Secret']);
});

test('changed server info is coalesced and sent at most once per second', function () {
    [$clock, $http, $registration] = registrationFixture();
    $registration->update('{"score":0}');
    $clock->now = 200_000_000;
    $registration->update('{"score":1}');
    $clock->now = 800_000_000;
    $registration->update('{"score":2}');
    expect($http->requests)->toHaveCount(1);

    $clock->now = 1_000_000_000;
    $registration->update();
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['body'])->toBe('{"score":2}');
    expect($http->requests[1]['headers']['Info-Serial'])->toBe('2');

    $clock->now = 2_000_000_000;
    $registration->update('{"score":2}');
    expect($http->requests)->toHaveCount(2);
});

test('a verified UDP challenge schedules an immediate retry with its token', function () {
    [, $http, $registration] = registrationFixture();
    $http->responses[]       = ['status' => 200, 'body' => '{"status":"need_challenge"}'];
    $registration->update('{}');
    $challenge = $http->requests[0]['headers']['Challenge-Secret'];
    expect($registration->onPacket(registrationChallenge($challenge)))->toBeTrue();
    $registration->update();

    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['headers']['Challenge-Token'])->toBe('master-token');
    expect($http->requests[1]['body'])->toBe('{}');
});

test('a UDP challenge received before its HTTP response is not lost', function () {
    [, $http, $registration] = registrationFixture();
    $http->responses[]       = function () use ($http, $registration): array {
        $registration->onPacket(registrationChallenge($http->requests[0]['headers']['Challenge-Secret'], 'early-token'));

        return ['status' => 200, 'body' => '{"status":"need_challenge"}'];
    };
    $registration->update('{}');
    $registration->update();

    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['headers']['Challenge-Token'])->toBe('early-token');
});

test('malformed foreign and wrong-protocol challenges do not change registration state', function (string $case) {
    [, $http, $registration] = registrationFixture();
    $http->responses[]       = ['status' => 200, 'body' => '{"status":"need_challenge"}'];
    $registration->update('{}');
    $secret = $http->requests[0]['headers']['Challenge-Secret'];
    $data   = match ($case) {
        'wrong secret'     => NetworkMessages::SERVERBROWSE_CHALLENGE.'different:tw0.6/ipv4'."\0token\0",
        'wrong family'     => NetworkMessages::SERVERBROWSE_CHALLENGE.str_replace('ipv4', 'ipv6', $secret)."\0token\0",
        'wrong version'    => NetworkMessages::SERVERBROWSE_CHALLENGE.str_replace('0.6', '0.7', $secret)."\0token\0",
        'no terminator'    => NetworkMessages::SERVERBROWSE_CHALLENGE.$secret."\0token",
        'empty token'      => NetworkMessages::SERVERBROWSE_CHALLENGE.$secret."\0\0",
        'long token'       => NetworkMessages::SERVERBROWSE_CHALLENGE.$secret."\0".str_repeat('x', 128)."\0",
        'header injection' => NetworkMessages::SERVERBROWSE_CHALLENGE.$secret."\0token\r\nSecret: injected\0",
        'browser query'    => NetworkMessages::SERVERBROWSE_GETINFO.chr(42),
        default            => throw new InvalidArgumentException('Unknown malformed challenge fixture'),
    };
    $registration->onPacket(new ConnectionlessPacket($data));
    $registration->update();
    expect($http->requests)->toHaveCount(1);
})->with(['wrong secret', 'wrong family', 'wrong version', 'no terminator', 'empty token', 'long token', 'header injection', 'browser query']);

test('the master can request server info again after accepting an earlier copy', function () {
    [$clock, $http, $registration] = registrationFixture();
    $registration->update('{"name":"Test"}');
    $http->responses[] = ['status' => 200, 'body' => '{"status":"need_info"}'];
    $clock->now        = 15_000_000_000;
    $registration->update();
    expect($http->requests[1]['body'])->toBe('');
    $registration->update();
    expect($http->requests)->toHaveCount(3);
    expect($http->requests[2]['body'])->toBe('{"name":"Test"}');
    expect($http->requests[2]['headers']['Info-Serial'])->toBe('0');
});

test('repeated requests for already supplied info do not cause a busy retry loop', function () {
    [$clock, $http, $registration] = registrationFixture();
    $http->responses[]             = ['status' => 200, 'body' => '{"status":"need_info"}'];
    $registration->update('{}');
    $clock->now = 14_000_000_000;
    $registration->update();
    expect($http->requests)->toHaveCount(1);
    $clock->now = 15_000_000_000;
    $registration->update();
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['body'])->toBe('{}');
});

test('registration errors are contained and retried without accepting the info serial', function (array|Throwable $response) {
    if (is_array($response)) {
        assert(isset($response['status'], $response['body']) && is_int($response['status']) && is_string($response['body']));
    }
    [$clock, $http, $registration] = registrationFixture();
    $http->responses[]             = $response instanceof Throwable ? $response : ['status' => $response['status'], 'body' => $response['body']];
    $registration->update('{}');
    $clock->now = 14_999_999_999;
    $registration->update();
    expect($http->requests)->toHaveCount(1);
    $clock->now++;
    $registration->update();
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['body'])->toBe('{}');
})->with([
    'transport failure' => [new RuntimeException('Connection refused')],
    'invalid json'      => [['status' => 200, 'body' => '<html>Unavailable</html>']],
    'missing status'    => [['status' => 200, 'body' => '{}']],
    'wrong type'        => [['status' => 200, 'body' => '{"status":true}']],
    'unknown status'    => [['status' => 200, 'body' => '{"status":"unknown"}']],
    'master error'      => [['status' => 400, 'body' => '{"status":"error","message":"Invalid registration"}']],
    'http failure'      => [['status' => 503, 'body' => '{"status":"success"}']],
]);

test('registration does not overlap HTTP requests or acknowledge newer unsent info', function () {
    [$clock, $http, $registration] = registrationFixture();
    $counts                        = [];
    Coroutine\run(function () use ($clock, $http, $registration, &$counts): void {
        $release           = new Channel(1);
        $http->responses[] = function () use ($release): array {
            $release->pop();

            return ['status' => 200, 'body' => '{"status":"success"}'];
        };
        $registration->update('{"score":0}');
        $clock->now = 1_000_000_000;
        $registration->update('{"score":1}');
        $counts[] = count($http->requests);
        $release->push(true);
        Coroutine::sleep(0.001);
        $registration->update();
        $counts[] = count($http->requests);
    });

    expect($counts)->toBe([1, 2]);
    expect($http->requests[1]['body'])->toBe('{"score":1}');
    expect($http->requests[1]['headers']['Info-Serial'])->toBe('1');
});

test('shutdown deletes the accepted listing with its secret and prevents further registration', function () {
    [$clock, $http, $registration] = registrationFixture();
    $registration->update('{}');
    $registration->shutdown();
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['headers'])->toBe([
        'Action'  => 'delete',
        'Address' => $http->requests[0]['headers']['Address'],
        'Secret'  => $http->requests[0]['headers']['Secret'],
    ]);
    expect($http->requests[1]['body'])->toBe('');
    expect($http->requests[1]['timeout'])->toBe(1.0);
    expect($registration->onPacket(registrationChallenge($http->requests[0]['headers']['Challenge-Secret'])))->toBeFalse();
    $clock->now = 60_000_000_000;
    $registration->update('{"new":true}');
    $registration->shutdown();
    expect($http->requests)->toHaveCount(2);
});

test('shutdown contains delete failures and does not delete unaccepted listings', function () {
    [, $http, $registration] = registrationFixture();
    $registration->shutdown();
    expect($http->requests)->toBe([]);

    [, $http, $registration] = registrationFixture();
    $registration->update('{}');
    $http->responses[] = new RuntimeException('Delete failed');
    $registration->shutdown();
    expect($http->requests)->toHaveCount(2);
});

test('listing deletion reports HTTP failures without blocking shutdown or retrying', function (int $status, string $body, bool $failed) {
    [$clock, $http, $registration] = registrationFixture();
    $registration->update('{}');
    $http->responses[] = ['status' => $status, 'body' => $body];
    $warnings          = [];
    $listener          = function (string $line) use (&$warnings): void {
        $warnings[] = $line;
    };
    Console::listen($listener, Console::LEVEL_WARN);

    try {
        $registration->shutdown();
        $registration->shutdown();
        $clock->now = 60_000_000_000;
        $registration->update('{}');
    } finally {
        Console::unlisten($listener);
    }

    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['headers']['Action'])->toBe('delete');
    expect($http->requests[1]['timeout'])->toBe(1.0);
    expect($warnings)->toHaveCount($failed ? 1 : 0);

    if ($failed) {
        expect($warnings[0])->toContain('Could not remove master server listing', 'HTTP '.$status);
    }
})->with([
    'success'                     => [200, '{"status":"success"}', false],
    'empty success'               => [204, '', false],
    'redirect'                    => [302, '', true],
    'rejected delete'             => [400, '{"status":"error","message":"Delete failed"}', true],
    'server error'                => [500, '<html>Unavailable</html>', true],
    'HTTP failure overrides body' => [503, '{"status":"success"}', true],
]);

test('an in-flight registration accepted during shutdown is deleted before completion', function () {
    [, $http, $registration] = registrationFixture();
    Coroutine\run(function () use ($http, $registration): void {
        $http->responses[] = function (): array {
            Coroutine::sleep(0.01);

            return ['status' => 200, 'body' => '{"status":"success"}'];
        };
        $registration->update('{}');
        $registration->shutdown();
    });

    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['headers']['Action'])->toBe('delete');
});

test('master registration is opt-in and absent registrations do not affect server ticks', function () {
    $server = new RegistrationTestServer;
    $server->addWorld();
    $socket = new RegistrationTestSocket;
    ob_start();
    try {
        $server->initialize($socket);
        $server->tick();
        $server->shutdown();
        $server->shutdown();
    } finally {
        $output = ob_get_clean();
    }
    assert(is_string($output));

    expect($server->clock->getCurrentTick())->toBe(1);
    expect($server->httpClient->requests)->toBe([]);
    expect($socket->shutdowns)->toBe(1);
    expect($output)->toContain('Master server registration: disabled');
    expect(substr_count($output, 'Shutting down server "Public server"'))->toBe(1);
    expect($output)->not->toContain('Server is listening on');
});

test('public registration merges all worlds and refreshes client state slots maps and gamemodes', function () {
    $server = new RegistrationTestServer(registerOnMasterServer: true);
    $dm     = $server->addWorld(slotsLimit: 12, spectatorSlots: 2);
    $ctf    = $server->addWorld('ctf1', slotsLimit: 20, spectatorSlots: 4);
    $socket = new RegistrationTestSocket;
    $server->initialize($socket);
    $first                     = $server->addClient($dm, $socket, 8400);
    $first->playerTee()->name  = 'First';
    $first->playerTee()->score = 7;
    $second                    = $server->addClient($ctf, $socket, 8401);
    $second->playerTee()->name = 'Second';
    $second->playerTee()->team = GameConstants::TEAM_SPECTATORS;
    $loading                   = $server->addClient($ctf, $socket, 8402, ConnectionSlot::STATE_LOADING);
    $server->addClient($dm, $socket, 8403, ConnectionSlot::STATE_CLOSED);
    $server->tick();
    $info = json_decode($server->httpClient->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);

    expect($info['name'])->toBe('Public server');
    expect($info['passworded'])->toBeTrue();
    expect($info['max_clients'])->toBe(32);
    expect($info['max_players'])->toBe(26);
    expect($info['num_players'])->toBe(2);
    expect($info['game_type'])->toBe('MW');
    expect($info['map'])->toBe(['name' => 'MultiWorld', 'crc' => 0, 'size' => 0]);
    expect($info['client_score_kind'])->toBe('points');
    expect(array_column($info['clients'], 'name'))->toBe(['First', 'Second', '(connecting)']);
    expect(array_column($info['clients'], 'is_player'))->toBe([true, false, true]);
    expect(array_column($info['clients'], 'score'))->toBe([7, 0, 0]);

    $second->closeConnection('Leaving');
    $first->playerTee()->score = 8;
    $server->updateRegistration();
    expect($server->httpClient->requests)->toHaveCount(1);
    $server->clock->now = 1_000_000_000;
    $server->updateRegistration();
    $info = json_decode($server->httpClient->requests[1]['body'], true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($info['clients'], 'name'))->toBe(['First', '(connecting)']);
    expect($info['clients'][0]['score'])->toBe(8);
    expect($loading->state)->toBe(ConnectionSlot::STATE_LOADING);
});

test('single-world registration uses the actual map and game type', function () {
    $server = new RegistrationTestServer(registerOnMasterServer: true);
    $world  = $server->addWorld('ctf1');
    $socket = new RegistrationTestSocket;
    $server->initialize($socket);
    $server->updateRegistration();
    $info = json_decode($server->httpClient->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);

    expect($info['game_type'])->toBe('CTF');
    expect($info['map'])->toBe(['name' => 'ctf1', 'crc' => $world->getMap()->getCrc(), 'size' => $world->getMap()->getSize()]);
    expect($info['clients'])->toBe([]);
    $builder = new ServerInfoPacketBuilder;
    expect($info)->toBe($builder->buildInfo([$world], [], 'Public server', true));
});

test('registration uses each socket port and routes challenge packets to their own socket', function () {
    $server = new RegistrationTestServer(registerOnMasterServer: true);
    $server->addWorld();
    $ipv4 = new RegistrationTestSocket;
    $ipv6 = new RegistrationTestSocket('::', 8304);
    $server->initialize($ipv4, $ipv6);
    $server->httpClient->responses = [
        ['status' => 200, 'body' => '{"status":"need_challenge"}'],
        ['status' => 200, 'body' => '{"status":"need_challenge"}'],
    ];
    $server->updateRegistration();
    expect(array_column($server->httpClient->requests, 'family'))->toBe([AF_INET, AF_INET6]);
    expect(array_column($server->httpClient->requests, 'localAddress'))->toBe(['0.0.0.0', '::']);
    expect($server->httpClient->requests[0]['url'])->toBe(MasterServerRegistration::URL);
    expect($server->httpClient->requests[1]['url'])->toBe(MasterServerRegistration::URL);
    expect($server->httpClient->requests[0]['headers']['Address'])->toEndWith(':8303');
    expect($server->httpClient->requests[1]['headers']['Address'])->toEndWith(':8304');
    expect($server->httpClient->requests[0]['headers']['Secret'])->toBe($server->httpClient->requests[1]['headers']['Secret']);
    expect($server->httpClient->requests[0]['headers']['Challenge-Secret'])->not->toBe($server->httpClient->requests[1]['headers']['Challenge-Secret']);

    $challenge = registrationChallenge($server->httpClient->requests[0]['headers']['Challenge-Secret']);
    $server->receive($challenge, $ipv6);
    $server->updateRegistration();
    expect($server->httpClient->requests)->toHaveCount(2);
    $server->receive($challenge, $ipv4);
    $server->updateRegistration();
    expect($server->httpClient->requests)->toHaveCount(3);
    expect($server->httpClient->requests[2]['family'])->toBe(AF_INET);
    expect($server->httpClient->requests[2]['headers']['Challenge-Token'])->toBe('master-token');

    $server->shutdown();
    $server->shutdown();
    expect($ipv4->shutdowns)->toBe(1);
    expect($ipv6->shutdowns)->toBe(1);
    expect($server->httpClient->requests)->toHaveCount(4);
    expect($server->httpClient->requests[3]['headers']['Action'])->toBe('delete');
});

test('a real game socket registers through a UDP challenge and removes its listing on shutdown', function (bool $signal, int $family) {
    $requests   = [];
    $output     = '';
    $error      = null;
    $exitStatus = null;
    Coroutine\run(function () use ($signal, $family, &$requests, &$output, &$error, &$exitStatus): void {
        $host     = $family === AF_INET6 ? '::1' : '127.0.0.1';
        $listener = new Socket($family, SOCK_STREAM, 0);
        if (! $listener->bind($host, 0) || ! $listener->listen()) {
            throw new RuntimeException('Could not create local master test listener');
        }
        $address = $listener->getsockname();
        if ($address === false) {
            throw new RuntimeException('Could not get the local master test port');
        }
        $process = proc_open([
            PHP_BINARY, __DIR__.'/../Support/RegisteredTestServer.php',
            'http://'.($family === AF_INET6 ? '[::1]' : $host).':'.$address['port'].'/register',
            $signal ? 'signal' : 'explicit', $host,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Could not start the game server test process');
        }
        $pid = proc_get_status($process)['pid'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            for ($i = 0; $i < 3; $i++) {
                $connection = $listener->accept(3.0);
                if ($connection === false) {
                    throw new RuntimeException('Missing HTTP request from the game server');
                }
                try {
                    $raw = '';
                    do {
                        $part = $connection->recv(8192, 2.0);
                        if ($part === false || $part === '') {
                            throw new RuntimeException('Incomplete HTTP request from the game server');
                        }
                        $raw .= $part;
                        $headerEnd = strpos($raw, "\r\n\r\n");
                        preg_match('/Content-Length: (\d+)/i', $raw, $length);
                    } while ($headerEnd === false || strlen($raw) < $headerEnd + 4 + (int) ($length[1] ?? 0));

                    $headers = [];
                    foreach (explode("\r\n", substr($raw, 0, $headerEnd)) as $line) {
                        if (str_contains($line, ': ')) {
                            [$key, $value]             = explode(': ', $line, 2);
                            $headers[strtolower($key)] = $value;
                        }
                    }
                    $requests[] = ['headers' => $headers, 'body' => substr($raw, $headerEnd + 4)];
                    if ($i === 0) {
                        Coroutine::sleep(0.06);
                        $udp = new Socket($family, SOCK_DGRAM, 0);
                        try {
                            $port = (int) substr($headers['address'], strrpos($headers['address'], ':') + 1);
                            $udp->sendto($host, $port, registrationChallenge($headers['challenge-secret'])->encodeToSend());
                        } finally {
                            $udp->close();
                        }
                    }
                    $body = $i === 0 ? '{"status":"need_challenge"}' : '{"status":"success"}';
                    $connection->sendAll("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
                    if ($signal && $i === 1) {
                        Coroutine::sleep(0.02);
                        proc_terminate($process);
                    }
                } finally {
                    $connection->close();
                }
            }
            $exitStatus = System::waitPid($pid, 3.0);
            if ($exitStatus === false) {
                throw new RuntimeException('Could not wait for the game server to shut down');
            }
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            if ($exitStatus === null || $exitStatus === false) {
                proc_terminate($process, 9);
                System::waitPid($pid, 1.0);
            }
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $listener->close();
        }
    });

    if ($error !== null) {
        throw new RuntimeException($error->getMessage().PHP_EOL.$output, previous: $error);
    }
    expect($output)->not->toContain('worker exit timeout', 'forced termination');
    expect($exitStatus['code'] ?? null)->toBe(0, $output);
    expect($exitStatus['signal'] ?? null)->toBe(0, $output);
    expect($requests)->toHaveCount(3);
    expect($requests[0]['headers']['challenge-secret'])->toEndWith($family === AF_INET6 ? ':tw0.6/ipv6' : ':tw0.6/ipv4');
    expect($requests[1]['headers']['challenge-token'])->toBe('master-token');
    expect($requests[2]['headers']['action'])->toBe('delete');
    expect($requests[2]['headers']['secret'])->toBe($requests[0]['headers']['secret']);
    expect($requests[2]['body'])->toBe('');
    $info = json_decode($requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($info['name'])->toBe('Registration integration test');
    expect($info['map']['name'])->toBe('dm1');
    $host       = $family === AF_INET6 ? '::1' : '127.0.0.1';
    $port       = (int) substr($requests[0]['headers']['address'], strrpos($requests[0]['headers']['address'], ':') + 1);
    $socketType = $family === AF_INET6 ? 'UDP/IPv6' : 'UDP/IPv4';
    expect($output)->toContain(
        'Starting server "Registration integration test" ('.NetworkParams::TICKS_PER_SECOND.' ticks/s)',
        'World=test Map=dm1 Gametype=DM Slots=64',
        'Master server registration: enabled',
        'Server is listening on '.$host.' port '.$port.' ('.$socketType.')',
        'Server stopped listening on '.$host.' port '.$port.' ('.$socketType.')',
    );
    expect(substr_count($output, 'Server is listening on '))->toBe(1);
    expect(substr_count($output, 'Server stopped listening on '))->toBe(1);
    expect($output)->toMatch('~Server is listening on .*Master server tw0\.6/~s');
    if (! $signal) {
        expect(substr_count($output, 'Shutting down server "Registration integration test"'))->toBe(1);
        preg_match('/registration-test-ticks=(\d+)/', $output, $ticks);
        expect((int) ($ticks[1] ?? 0))->toBeGreaterThanOrEqual(20);
    }
})->with([
    'IPv4 shutdown API'       => [false, AF_INET],
    'IPv4 termination signal' => [true, AF_INET],
    'IPv6 shutdown API'       => [false, AF_INET6],
    'IPv6 termination signal' => [true, AF_INET6],
]);

test('HTTP registration performs a real nonblocking local POST with the expected headers and body', function () {
    $request = '';
    $result  = null;
    Coroutine\run(function () use (&$request, &$result): void {
        $listener = new Socket(AF_INET, SOCK_STREAM, 0);
        if (! $listener->bind('127.0.0.1', 0) || ! $listener->listen()) {
            throw new RuntimeException('Could not create local HTTP test listener');
        }
        $address = $listener->getsockname();
        if ($address === false) {
            throw new RuntimeException('Could not get the local HTTP test port');
        }
        Coroutine::create(function () use ($listener, &$request): void {
            $connection = $listener->accept(2.0);
            if ($connection === false) {
                throw new RuntimeException('No local HTTP test connection received');
            }
            try {
                while (! str_contains($request, "\r\n\r\n{}")) {
                    $part = $connection->recv(8192, 2.0);
                    if ($part === false || $part === '') {
                        throw new RuntimeException('Incomplete local HTTP test request');
                    }
                    $request .= $part;
                }
                Coroutine::sleep(0.01);
                $body = '{"status":"success"}';
                $connection->sendAll("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            } finally {
                $connection->close();
                $listener->close();
            }
        });
        $result = (new MasterServerHttpClient)->post(
            'http://localhost:'.$address['port'].'/register?test=1',
            AF_INET,
            ['Secret' => 'test-secret', 'Info-Serial' => '0'],
            '{}',
            timeout: 2.0,
        );
    });

    expect($result)->toBe(['status' => 200, 'body' => '{"status":"success"}']);
    expect($request)->toStartWith("POST /register?test=1 HTTP/1.1\r\n");
    expect(strtolower($request))->toContain("content-type: application/json\r\n", "secret: test-secret\r\n", "info-serial: 0\r\n");
    expect($request)->toEndWith("\r\n\r\n{}");
});
