<?php

namespace TeeFrame\Server\Sockets;

use Swoole\Event;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use TeeFrame\Network\NetworkParams;

class WebSocketSocket extends AbstractSocket
{
    /** @var array<int, array{address: string, port: int}> */
    protected array $peers = [];

    /** @var array<string, int> */
    protected array $peerIds = [];

    public function __construct(string $host, int $port, protected bool $tls = false)
    {
        parent::__construct($host, $port);
        $this->settings = [
            'open_http_protocol'      => true,
            'open_websocket_protocol' => true,
            'websocket_compression'   => false,
            'package_max_length'      => NetworkParams::MAXIMUM_PACKET_SIZE + 14,
        ];
    }

    public function getType(): int
    {
        return (str_contains($this->host, ':') ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP) | ($this->tls ? SWOOLE_SSL : 0);
    }

    public function createServer(): Server
    {
        return new WebSocketServer($this->host, $this->port, SWOOLE_BASE, $this->getType());
    }

    public function onPacket(callable $callback): void
    {
        $this->listener->on('request', function (Request $request, Response $response): void {
            $response->status(426);
            $response->header('Upgrade', 'websocket');
            $response->end('WebSocket upgrade required');
        });

        $this->listener->on('open', function (WebSocketServer $server, Request $request): void {
            /** @var array{remote_ip: string, remote_port: int}|false $info */
            $info = $server->getClientInfo($request->fd);
            if ($info === false) {
                return;
            }
            $peer                                              = ['address' => $info['remote_ip'], 'port' => $info['remote_port']];
            $this->peers[$request->fd]                         = $peer;
            $this->peerIds[$peer['address'].':'.$peer['port']] = $request->fd;
        });

        $this->listener->on('message', function (WebSocketServer $server, Frame $frame) use ($callback): void {
            $peer = $this->peers[$frame->fd] ?? null;
            if ($peer === null || ! isset($this->peerIds[$peer['address'].':'.$peer['port']])) {
                return;
            }
            if ($frame->opcode === WEBSOCKET_OPCODE_PING || $frame->opcode === WEBSOCKET_OPCODE_PONG) {
                return;
            }

            if ($frame->opcode !== WEBSOCKET_OPCODE_BINARY) {
                $this->closePeer($frame->fd, SWOOLE_WEBSOCKET_CLOSE_DATA_ERROR, 'Expected a binary game packet');

                return;
            }

            if (strlen($frame->data) > NetworkParams::MAXIMUM_PACKET_SIZE) {
                $this->closePeer($frame->fd, SWOOLE_WEBSOCKET_CLOSE_MESSAGE_TOO_BIG, 'Game packet is too large');

                return;
            }

            $callback($frame->data, $peer);
        });
    }

    public function onClose(callable $callback): void
    {
        $this->listener->on('close', function (Server $server, int $fd) use ($callback): void {
            $peer = $this->peers[$fd] ?? null;
            if ($peer === null) {
                return;
            }

            unset($this->peers[$fd], $this->peerIds[$peer['address'].':'.$peer['port']]);
            $callback($peer);
        });
    }

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($ip) && is_int($port) && is_string($send_data));
        $fd = $this->peerIds[$ip.':'.$port] ?? null;
        if ($fd === null) {
            return false;
        }

        return $this->getWebSocketServer()->push($fd, $send_data, WEBSOCKET_OPCODE_BINARY);
    }

    public function disconnect(string $address, int $port): void
    {
        $fd = $this->peerIds[$address.':'.$port] ?? null;
        if ($fd !== null) {
            $this->closePeer($fd);
        }
    }

    protected function closePeer(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): void
    {
        $peer = $this->peers[$fd];
        unset($this->peerIds[$peer['address'].':'.$peer['port']]);

        // Swoole still owns its fragment buffer during onMessage; close after it returns.
        Event::defer(function () use ($fd, $code, $reason): void {
            if (isset($this->peers[$fd])) {
                $this->getWebSocketServer()->disconnect($fd, $code, $reason);
            }
        });
    }

    protected function getWebSocketServer(): WebSocketServer
    {
        $server = $this->socketServer->getServer();
        assert($server instanceof WebSocketServer);

        return $server;
    }
}
