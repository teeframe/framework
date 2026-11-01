<?php

namespace TeeFrame\Server\Sockets;

use Swoole\Server;

class SocketServer
{
    protected Server $server;
    protected bool $stopped = false;

    /** @param non-empty-array<AbstractSocket> $sockets */
    public function __construct(array $sockets)
    {
        $primary = reset($sockets);
        foreach ($sockets as $socket) {
            if ($socket instanceof WebSocketSocket) {
                $primary = $socket;

                break;
            }
        }

        // Swoole allows one server per process. Other transports are listeners on it.
        $this->server = $primary->createServer();
        foreach ($sockets as $socket) {
            $listener = $socket === $primary
                ? $this->server->ports[0]
                : $this->server->listen($socket->host, $socket->port, $socket->getType());
            if ($listener === false) {
                throw new \RuntimeException('Could not listen on '.$socket->host.':'.$socket->port);
            }
            $socket->bind($this, $listener);
        }
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function on(string $event, callable $callback): bool
    {
        return $this->server->on($event, $callback);
    }

    public function start(): bool
    {
        return $this->server->start();
    }

    public function shutdown(): bool
    {
        if ($this->stopped) {
            return true;
        }
        $this->stopped = true;

        return $this->server->shutdown();
    }
}
