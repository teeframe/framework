<?php

namespace TeeFrame\Server\Sockets;

use Swoole\Server;
use Swoole\Server\Port;

abstract class AbstractSocket
{
    protected SocketServer $socketServer;
    protected Port $listener;

    /** @var array<string, mixed> */
    protected array $settings = [
        'open_http_protocol'      => false,
        'open_websocket_protocol' => false,
    ];

    public function __construct(public string $host, public int $port) {}

    public function getType(): int
    {
        return str_contains($this->host, ':') ? SWOOLE_SOCK_UDP6 : SWOOLE_SOCK_UDP;
    }

    public function createServer(): Server
    {
        return new Server($this->host, $this->port, SWOOLE_BASE, $this->getType());
    }

    public function bind(SocketServer $socketServer, Port $listener): void
    {
        $this->socketServer = $socketServer;
        $this->listener     = $listener;
        $this->port         = $listener->port;
        $listener->set($this->settings);
    }

    /** @param array<string, mixed> $settings */
    public function set(array $settings): bool
    {
        $this->settings = [...$this->settings, ...$settings];
        if (isset($this->listener)) {
            $this->listener->set($settings);
        }

        return true;
    }

    /** @param callable(string, array{address: string, port: int}): void $callback */
    public function onPacket(callable $callback): void
    {
        $this->listener->on('packet', function (Server $server, string $data, array $clientInfo) use ($callback): void {
            /** @var array{address: string, port: int} $clientInfo */
            $callback($data, $clientInfo);
        });
    }

    /** @param callable(array{address: string, port: int}): void $callback */
    public function onClose(callable $callback): void {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        return $this->socketServer->getServer()->sendto(
            $ip,
            $port,
            $send_data,
            $server_socket === -1 ? $this->listener->sock : $server_socket,
        );
    }

    public function disconnect(string $address, int $port): void {}

    public function shutdown(): bool
    {
        return $this->socketServer->shutdown();
    }
}
