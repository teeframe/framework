<?php

namespace TeeFrame\Server\Sockets;

class UdpSocket extends AbstractSocket
{
    public function __construct(string $host, int $port)
    {
        parent::__construct($host, $port, SWOOLE_BASE, SWOOLE_SOCK_UDP);
    }
}
