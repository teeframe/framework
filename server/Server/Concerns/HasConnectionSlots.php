<?php

namespace TeeFrame\Server\Server\Concerns;

use TeeFrame\Server\Connection\ConnectionSlot;

trait HasConnectionSlots
{
    const MAX_CONNECTIONS = 16;

    /**
     * @var array<int, ConnectionSlot>
     */
    public array $connectionSlots = [];

    public function getConnectionSlots(): array
    {
        return $this->connectionSlots;
    }

    public function closeAllConnections(string $reason): void
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                continue;
            }

            $connection->closeConnection($reason);
        }
    }

    protected function initializeConnectionSlots(): void
    {
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            $this->connectionSlots[$i] = new ConnectionSlot($i);
        }
    }

    protected function tryToMatchConnectionSlot(array $clientInfo): ConnectionSlot|false
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                continue;
            }

            if ($connection->destinationAddress !== $clientInfo['address'] || $connection->destinationPort !== $clientInfo['port']) {
                continue;
            }

            return $connection;
        }

        return false;
    }

    protected function getAvailableConnectionSlot(): ConnectionSlot|false
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                return $connection;
            }
        }

        return false;
    }
}
