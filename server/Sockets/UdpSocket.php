<?php

namespace TeeFrame\Server\Sockets;

class UdpSocket extends AbstractSocket
{
    public function __construct(string $host, int $port)
    {
        parent::__construct($host, $port, SWOOLE_BASE, str_contains($host, ':') ? SWOOLE_SOCK_UDP6 : SWOOLE_SOCK_UDP);
    }
}
