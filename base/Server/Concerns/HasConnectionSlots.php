<?php

namespace Base\Server\Concerns;

use Base\Connection\ConnectionSlot;

trait HasConnectionSlots
{
    const MAX_CONNECTIONS = 16;

    /**
     * @var array<int, ConnectionSlot>
     */
    public array $connectionSlots = [];

    protected function initializeConnectionSlots(): void
    {
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            $this->connectionSlots[$i] = new ConnectionSlot;
        }
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

    public function closeAllConnections(string $reason): void
    {
        foreach ($this->connectionSlots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_EMPTY) {
                continue;
            }

            $connection->closeConnection($reason);
        }
    }
}
