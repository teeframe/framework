<?php

namespace Base;

use Network\Decoder\DecodedPacket;
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

    public function onPacket(string $rawData, array $clientInfo)
    {
        $packet = DecodedPacket::decodeFromRaw($rawData);

        // Already connected client
        if ($slotConnection = $this->tryToMatchSlotConnection($clientInfo)) {
            $slotConnection->feed($packet);

            return;
        }

        // New client (and slot available)
        if ($slotConnection = $this->getAvailableSlotConnection()) {
            $slotConnection->connect($clientInfo);

            return;
        }

        // TODO: Server is full
    }

    public function tryToMatchSlotConnection(array $clientInfo): SlotConnection|false
    {
        foreach ($this->slotConnections as $connection) {
            if ($connection->state === SlotConnection::STATE_EMPTY) {
                continue;
            }

            if ($connection->clientAddress !== $clientInfo['address'] || $connection->clientPort !== $clientInfo['port']) {
                continue;
            }

            return $connection;
        }

        return false;
    }

    public function getAvailableSlotConnection(): SlotConnection|false
    {
        foreach ($this->slotConnections as $connection) {
            if ($connection->state === SlotConnection::STATE_EMPTY) {
                return $connection;
            }
        }

        return false;
    }
}
