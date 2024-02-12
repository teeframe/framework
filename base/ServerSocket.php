<?php

namespace Base;

use Network\Decoder\DecodedPacket;
use Network\Encoder\PackageEncoder;
use Network\Enums\Network;
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

    public function start(): bool
    {
        Instance::$server = $this;

        echo 'Server started on '.$this->host.':'.$this->port.PHP_EOL;

        return parent::start();
    }

    public function shutdown(): bool
    {
        return parent::shutdown();
    }

    public function onPacket(string $rawData, array $clientInfo): void
    {
        $packet = DecodedPacket::decodeFromRaw($rawData);
        if ($packet === false) {
            return;
        }

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

        // Server is full...
        PackageEncoder::makeControlMessage(Network::CTRLMSG_CLOSE, 'The server is full')
            ->send($clientInfo['address'], $clientInfo['port']);
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
