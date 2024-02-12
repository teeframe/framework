<?php

namespace Base;

use App\Enums\NetConnState;
use Swoole\Server;

class ServerSocket extends Server
{
    const MAX_CONNECTIONS = 16;

    public array $slotConnections = [];

    public function __construct(string $host, int $port)
    {
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            $this->slotConnections[$i] = new SlotConnection;
        }

        parent::__construct($host, $port, SWOOLE_BASE, SWOOLE_SOCK_UDP);
    }

    public function getAvailableSlotConnection(): ?SlotConnection
    {
        foreach ($this->slotConnections as $connection) {
            if ($connection->state === NetConnState::OFFLINE) {
                return $connection;
            }
        }

        return null;
    }
}
