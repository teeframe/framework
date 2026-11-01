<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Socket;
use Swoole\WebSocket\Frame;
use TeeFrame\Core\TickHandler;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvReadyToEnterChunk;
use TeeFrame\Network\Chunks\System\ConReadyChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\ConnectionHandler;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;
use TeeFrame\Server\Sockets\UdpSocket;
use TeeFrame\Server\Sockets\WebSocketSocket;

class WebSocketRecordingSocket extends AbstractSocket
{
    /** @var list<array{address: string, port: int}> */
    public array $disconnectedPeers = [];

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        return true;
    }

    public function disconnect(string $address, int $port): void
    {
        $this->disconnectedPeers[] = ['address' => $address, 'port' => $port];
    }
}

class SocketGameClient
{
    protected int $sequence = 0;
    protected int $ack      = 0;

    /**
     * @param  Closure(string): bool  $send
     * @param  Closure(): string  $receive
     */
    public function __construct(protected Closure $send, protected Closure $receive) {}

    public function send(AbstractPacket $packet): void
    {
        if (! ($this->send)($packet->encodeToSend())) {
            throw new RuntimeException('Could not send a game packet');
        }
    }

    public function sendChunks(AbstractChunk ...$chunks): void
    {
        foreach ($chunks as $chunk) {
            if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
                $this->sequence = ($this->sequence + 1) % NetworkParams::MAXIMUM_ACK_NUMBER;
                $chunk->setSequence($this->sequence);
            }
        }
        $this->send(new DefaultPacket($chunks, ack: $this->ack));
    }

    /** @param Closure(AbstractPacket): bool $predicate */
    public function receiveUntil(Closure $predicate): AbstractPacket
    {
        for ($i = 0; $i < 100; $i++) {
            $packet = PacketDecoder::decodeFromRaw(($this->receive)());
            if ($packet === false) {
                throw new RuntimeException('Received an invalid game packet');
            }
            if ($packet instanceof DefaultPacket) {
                foreach ($packet->getChunks() as $chunk) {
                    if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
                        $this->ack = $chunk->getSequence();
                    }
                }
            }
            if ($predicate($packet)) {
                return $packet;
            }
        }

        throw new RuntimeException('Expected game packet was not received');
    }

    /**
     * @template T of AbstractChunk
     *
     * @param  class-string<T>  $class
     * @param  (Closure(T): bool)|null  $predicate
     * @return T
     */
    public function receiveChunk(string $class, ?Closure $predicate = null): AbstractChunk
    {
        $packet = $this->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof DefaultPacket
            && array_any($packet->getChunks(), fn (AbstractChunk $chunk): bool => $chunk instanceof $class && ($predicate === null || $predicate($chunk))));
        assert($packet instanceof DefaultPacket);
        foreach ($packet->getChunks() as $chunk) {
            if ($chunk instanceof $class && ($predicate === null || $predicate($chunk))) {
                return $chunk;
            }
        }

        throw new LogicException('Expected chunk was not present');
    }

    public function enter(string $name): void
    {
        $this->send(new ControlMessage(NetworkMessages::CONTROL_CONNECT));
        $this->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage && $packet->getControlMessage() === NetworkMessages::CONTROL_CONNECT_ACCEPT);
        $this->sendChunks(new InfoChunk('0.6 626fce9a778df4d4'));
        $this->receiveChunk(MapChangeChunk::class);
        $this->sendChunks(new ReadyChunk);
        $this->receiveChunk(ConReadyChunk::class);
        $this->sendChunks(new ClStartInfoChunk($name, '', 0, 'default', false, 0, 0));
        $this->receiveChunk(SvReadyToEnterChunk::class);
        $this->sendChunks(new EnterGameChunk);
    }

    public function close(): void
    {
        $this->send(new ControlMessage(NetworkMessages::CONTROL_CLOSE, ack: $this->ack));
    }

    /** @return list<string> */
    public function serverInfo(): array
    {
        $this->send(new ConnectionlessPacket(NetworkMessages::SERVERBROWSE_GETINFO.chr(42), extraToken: 0));
        $packet = $this->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ConnectionlessPacket
            && str_starts_with($packet->getData(), NetworkMessages::SERVERBROWSE_INFO_EXTENDED));
        assert($packet instanceof ConnectionlessPacket);

        return explode("\0", substr($packet->getData(), strlen(NetworkMessages::SERVERBROWSE_INFO_EXTENDED), -1));
    }
}

/** @param Closure(list<int>): mixed $callback */
function withWebSocketServer(Closure $callback, bool $webSocketOnly = false, string $host = '127.0.0.1'): mixed
{
    $result = null;
    $error  = null;
    $output = '';
    Coroutine\run(function () use ($callback, $webSocketOnly, $host, &$result, &$error, &$output): void {
        $process = proc_open([PHP_BINARY, __DIR__.'/../Support/WebSocketTestServer.php', $webSocketOnly ? 'websocket' : 'mixed', $host], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Could not start the WebSocket test server');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        try {
            $deadline = microtime(true) + 3;
            do {
                $output .= stream_get_contents($pipes[1]);
                if (str_contains($output, "\n")) {
                    break;
                }
                Coroutine::sleep(0.01);
            } while (microtime(true) < $deadline && proc_get_status($process)['running']);

            $ports = json_decode(explode("\n", $output)[0], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($ports) || count($ports) !== ($webSocketOnly ? 1 : 3) || ! array_is_list($ports) || ! array_all($ports, fn (mixed $port): bool => is_int($port) && $port > 0)) {
                throw new RuntimeException('The test server did not report its listener ports');
            }
            /** @var list<int> $ports */
            $result = $callback($ports);
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            proc_terminate($process);
            $deadline = microtime(true) + 4;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                Coroutine::sleep(0.01);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    });
    if ($error !== null) {
        throw new RuntimeException($error->getMessage().PHP_EOL.$output, previous: $error);
    }

    return $result;
}

function openGameWebSocket(int $port, string $host = '127.0.0.1'): Client
{
    $client = new Client($host, $port);
    $client->set(['timeout' => 1.0, 'websocket_mask' => true, 'open_websocket_pong_frame' => true]);
    if (! $client->upgrade('/')) {
        throw new RuntimeException('WebSocket upgrade failed: '.$client->statusCode.' '.$client->errMsg);
    }

    return $client;
}

function webSocketGameClient(Client $client): SocketGameClient
{
    return new SocketGameClient(
        fn (string $data): bool => $client->push($data, WEBSOCKET_OPCODE_BINARY),
        function () use ($client): string {
            $frame = $client->recv(1.0);
            if (! $frame instanceof Frame || $frame->opcode !== WEBSOCKET_OPCODE_BINARY) {
                throw new RuntimeException('Expected a binary WebSocket game packet');
            }

            return $frame->data;
        },
    );
}

function udpGameClient(Socket $socket, int $port): SocketGameClient
{
    if (! $socket->connect('127.0.0.1', $port)) {
        throw new RuntimeException('Could not connect the UDP game client');
    }

    return new SocketGameClient(
        fn (string $data): bool => $socket->send($data) !== false,
        function () use ($socket): string {
            $data = $socket->recv(NetworkParams::MAXIMUM_PACKET_SIZE, 1.0);
            if ($data === false || $data === '') {
                throw new RuntimeException('Expected a UDP game packet');
            }

            return $data;
        },
    );
}

test('UDP and WebSocket socket types support IPv4 IPv6 and optional TLS', function () {
    expect((new UdpSocket('127.0.0.1', 8303))->getType())->toBe(SWOOLE_SOCK_UDP);
    expect((new UdpSocket('::1', 8303))->getType())->toBe(SWOOLE_SOCK_UDP6);
    expect((new WebSocketSocket('127.0.0.1', 8304))->getType())->toBe(SWOOLE_SOCK_TCP);
    expect((new WebSocketSocket('::1', 8304))->getType())->toBe(SWOOLE_SOCK_TCP6);
    expect((new WebSocketSocket('::1', 8304, tls: true))->getType())->toBe(SWOOLE_SOCK_TCP6 | SWOOLE_SSL);
});

test('connection matching includes the listener and closes only the selected transport', function () {
    $clock   = new TickHandler;
    $handler = new ConnectionHandler($clock);
    $world   = new TestWorld($clock, new Map);
    $first   = new WebSocketRecordingSocket('127.0.0.1', 8303);
    $second  = new WebSocketRecordingSocket('127.0.0.1', 8304);
    $peer    = ['address' => '127.0.0.1', 'port' => 45000];
    $slots   = [];
    foreach ([$first, $second] as $socket) {
        $slot = $handler->startNew($socket, $world);
        if ($slot === false) {
            throw new RuntimeException('No test connection slot');
        }
        $handler->startConnectionHandshake($slot, $peer['address'], $peer['port']);
        $slots[] = $slot;
    }
    expect($handler->tryToMatch($peer, $first))->toBe($slots[0]);
    expect($handler->tryToMatch($peer, $second))->toBe($slots[1]);
    $slots[0]->closeConnection('Closing first transport');
    expect($first->disconnectedPeers)->toBe([$peer]);
    expect($second->disconnectedPeers)->toBe([]);
    expect($handler->tryToMatch($peer, $first))->toBeFalse();
    expect($handler->tryToMatch($peer, $second))->toBe($slots[1]);
    expect($slots[1]->state)->toBe(ConnectionSlot::STATE_CONNECTING);
});

test('WebSocket and UDP clients share the game handshake chat and world capacity', function () {
    $result = withWebSocketServer(function (array $ports): array {
        $ws    = openGameWebSocket($ports[1]);
        $udp   = new Socket(AF_INET, SOCK_DGRAM, 0);
        $other = null;
        try {
            $wsGame  = webSocketGameClient($ws);
            $udpGame = udpGameClient($udp, $ports[0]);
            $wsGame->enter('WebSocket');
            $udpGame->enter('UDP');
            $info     = $wsGame->serverInfo();
            $rejected = openGameWebSocket($ports[1]);
            try {
                $rejectedGame = webSocketGameClient($rejected);
                $rejectedGame->send(new ControlMessage(NetworkMessages::CONTROL_CONNECT));
                $rejection = $rejectedGame->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage);
                assert($rejection instanceof ControlMessage);
                $fullReason = explode("\0", $rejection->getControlMessageExtra(), 2)[0];
            } finally {
                $rejected->close();
            }
            $wsGame->sendChunks(new ClSayChunk(false, 'hello from WebSocket'));
            $selfChat = $wsGame->receiveChunk(SvChatChunk::class)->text;
            $udpChat  = $udpGame->receiveChunk(SvChatChunk::class, fn (SvChatChunk $chunk): bool => $chunk->text === 'hello from WebSocket')->text;

            $ws->close();
            for ($i = 0; $i < 20; $i++) {
                $afterClose = $udpGame->serverInfo();
                if ($afterClose[10] === '1') {
                    break;
                }
                Coroutine::sleep(0.01);
            }
            $other       = openGameWebSocket($ports[1]);
            $replacement = webSocketGameClient($other);
            $replacement->enter('Replacement');
            $rejoined = $replacement->serverInfo();
            $replacement->close();
            Coroutine::sleep(0.02);
            $afterProtocolClose = $udpGame->serverInfo();

            return compact('info', 'fullReason', 'selfChat', 'udpChat', 'afterClose', 'rejoined', 'afterProtocolClose');
        } finally {
            $ws->close();
            $other?->close();
            $udp->close();
        }
    });

    expect($result['info'][10])->toBe('2');
    expect($result['info'])->toContain('WebSocket', 'UDP');
    expect($result['fullReason'])->toBe('The server is full');
    expect($result['selfChat'])->toBe('hello from WebSocket');
    expect($result['udpChat'])->toBe('hello from WebSocket');
    expect($result['afterClose'][10])->toBe('1');
    expect($result['afterClose'])->not->toContain('WebSocket');
    expect($result['rejoined'][10])->toBe('2');
    expect($result['rejoined'])->toContain('Replacement', 'UDP');
    expect($result['afterProtocolClose'][10])->toBe('1');
});

test('a standalone WebSocket listener supports the game handshake over IPv4 and IPv6', function (string $host) {
    $info = withWebSocketServer(function (array $ports) use ($host): array {
        $client = openGameWebSocket($ports[0], $host);
        try {
            $game = webSocketGameClient($client);
            $game->enter('Standalone');

            return $game->serverInfo();
        } finally {
            $client->close();
        }
    }, webSocketOnly: true, host: $host);

    expect($info[2])->toBe('WebSocket test');
    expect($info[10])->toBe('1');
    expect($info)->toContain('Standalone');
})->with(['IPv4' => '127.0.0.1', 'IPv6' => '::1']);

test('multiple UDP listeners isolate identical client endpoints and reply from their own ports', function () {
    $result = withWebSocketServer(function (array $ports): array {
        $client  = new Socket(AF_INET, SOCK_DGRAM, 0);
        $replies = [];
        try {
            foreach ([$ports[0], $ports[2]] as $port) {
                $client->sendto('127.0.0.1', $port, (new ControlMessage(NetworkMessages::CONTROL_CONNECT))->encodeToSend());
                $peer = null;
                $data = $client->recvfrom($peer, 1.0);
                if ($data === false || ! is_array($peer)) {
                    throw new RuntimeException('Missing listener-specific UDP reply');
                }
                $packet    = PacketDecoder::decodeFromRaw($data);
                $replies[] = [$peer['port'], $packet instanceof ControlMessage ? $packet->getControlMessage() : null];
            }

            return ['ports' => [$ports[0], $ports[2]], 'replies' => $replies];
        } finally {
            $client->close();
        }
    });

    expect($result['replies'])->toBe([
        [$result['ports'][0], NetworkMessages::CONTROL_CONNECT_ACCEPT],
        [$result['ports'][1], NetworkMessages::CONTROL_CONNECT_ACCEPT],
    ]);
});

test('WebSocket messages reassemble fragments and reject text and oversized packets', function () {
    $result = withWebSocketServer(function (array $ports): array {
        $result = [];
        $ws     = openGameWebSocket($ports[1]);
        try {
            $ws->push('ping', WEBSOCKET_OPCODE_PING);
            $pong           = $ws->recv(1.0);
            $result['pong'] = $pong instanceof Frame ? [$pong->opcode, $pong->data] : null;
            $ws->push("\0", WEBSOCKET_OPCODE_BINARY);
            $packet = (new ControlMessage(NetworkMessages::CONTROL_CONNECT))->encodeToSend();
            $ws->push(substr($packet, 0, 2), WEBSOCKET_OPCODE_BINARY, 0);
            $ws->push(substr($packet, 2), WEBSOCKET_OPCODE_CONTINUATION, SWOOLE_WEBSOCKET_FLAG_FIN);
            $reply = webSocketGameClient($ws)->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage);
            assert($reply instanceof ControlMessage);
            $result['fragmentedConnect'] = $reply->getControlMessage();
        } finally {
            $ws->close();
        }

        foreach (['text', 'oversized', 'oversized fragments', 'fragmented invalid version'] as $case) {
            $ws = openGameWebSocket($ports[1]);
            try {
                if ($case === 'text') {
                    $ws->push('not a binary packet', WEBSOCKET_OPCODE_TEXT);
                } elseif ($case === 'oversized') {
                    $ws->push(str_repeat('x', NetworkParams::MAXIMUM_PACKET_SIZE + 1), WEBSOCKET_OPCODE_BINARY);
                } elseif ($case === 'oversized fragments') {
                    $ws->push(str_repeat('x', 1000), WEBSOCKET_OPCODE_BINARY, 0);
                    $ws->push(str_repeat('x', 401), WEBSOCKET_OPCODE_CONTINUATION, SWOOLE_WEBSOCKET_FLAG_FIN);
                } else {
                    $game = webSocketGameClient($ws);
                    $game->send(new ControlMessage(NetworkMessages::CONTROL_CONNECT));
                    $game->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage);
                    $packet = (new DefaultPacket([(new InfoChunk('invalid version'))->setSequence(1)], ack: 0))->encodeToSend();
                    $ws->push(substr($packet, 0, 2), WEBSOCKET_OPCODE_BINARY, 0);
                    $ws->push(substr($packet, 2), WEBSOCKET_OPCODE_CONTINUATION, SWOOLE_WEBSOCKET_FLAG_FIN);
                    $rejection = $game->receiveUntil(fn (AbstractPacket $packet): bool => $packet instanceof ControlMessage);
                    assert($rejection instanceof ControlMessage);
                    $result['versionReason'] = explode("\0", $rejection->getControlMessageExtra(), 2)[0];
                }
                $reply         = $ws->recv(1.0);
                $reply         = $ws->recv(1.0);
                $result[$case] = ($reply instanceof Frame && $reply->opcode === WEBSOCKET_OPCODE_CLOSE) || ! $ws->connected;
            } finally {
                $ws->close();
            }
            $http = new Client('127.0.0.1', $ports[1]);
            $http->set(['timeout' => 1.0]);
            try {
                if (! $http->get('/')) {
                    throw new RuntimeException('HTTP request after '.$case.' failed: '.$http->statusCode.' '.$http->errCode.' '.$http->errMsg);
                }
                $result['httpStatus'] = $http->statusCode;
            } finally {
                $http->close();
            }
        }

        return $result;
    });

    expect($result['pong'])->toBe([WEBSOCKET_OPCODE_PONG, 'ping']);
    expect($result['fragmentedConnect'])->toBe(NetworkMessages::CONTROL_CONNECT_ACCEPT);
    expect($result['text'])->toBeTrue();
    expect($result['oversized'])->toBeTrue();
    expect($result['oversized fragments'])->toBeTrue();
    expect($result['fragmented invalid version'])->toBeTrue();
    expect($result['versionReason'])->toBe('Wrong client version');
    expect($result['httpStatus'])->toBe(426);
});
