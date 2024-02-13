<?php

namespace Base;

use Base\Connection\ConnectionSlot;
use Network\Decoder\DecodedPacket;
use Network\Encoder\PackageEncoder;
use Network\Enums\Network;
use Swoole\Server;

class ServerSocket extends Server
{
    const MAX_CONNECTIONS = 16;

    public array $connectionSlots = [];

    public function __construct(string $host, int $port)
    {
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            $this->connectionSlots[$i] = new ConnectionSlot;
        }

        parent::__construct($host, $port, SWOOLE_BASE, SWOOLE_SOCK_UDP);
    }

    public function start(): bool
    {
        Instance::$server = $this;

        Console::info("Server started on {$this->host}:{$this->port}");

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

        // Connless package
        if ($packet->getFlags() & Network::PACKETFLAG_CONNLESS) {
            Console::warn('Connless package received');

            return;
        }

        // Known client (found it slot connection)
        if ($connectionSlot = $this->tryToMatchConnectionSlot($clientInfo)) {
            $connectionSlot->feedConnection($packet);

            return;
        }

        // New client (and slot available)
        if ($connectionSlot = $this->getAvailableConnectionSlot()) {
            $connectionSlot->startHandshakeConnection($clientInfo['address'], $clientInfo['port']);

            return;
        }

        // Server is full...
        PackageEncoder::makeControlMessage(Network::CTRLMSG_CLOSE, 'The server is full')
            ->send($clientInfo['address'], $clientInfo['port']);
    }

    public function tryToMatchConnectionSlot(array $clientInfo): ConnectionSlot|false
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                continue;
            }

            if ($connection->clientAddress !== $clientInfo['address'] || $connection->clientPort !== $clientInfo['port']) {
                continue;
            }

            return $connection;
        }

        return false;
    }

    public function getAvailableConnectionSlot(): ConnectionSlot|false
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                return $connection;
            }
        }

        return false;
    }
}
