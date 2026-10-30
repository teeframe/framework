<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionHandler
{
    use Concerns\HasHandshakeHandler;

    /**
     * @var ConnectionSlot[]
     */
    protected array $slots = [];

    public function __construct(protected TickHandler $tickHandler) {}

    public function startNew(AbstractSocket $socket, AbstractWorld $world): ConnectionSlot|false
    {
        $this->clearConnections();

        if ($this->isWorldFull($world)) {
            return false;
        }

        $this->slots[] = $slot = new ConnectionSlot($socket, $world, $this->tickHandler);

        return $slot;
    }

    /**
     * @param  array{address: string, port: int}  $clientInfo
     */
    public function tryToMatch(array $clientInfo): ConnectionSlot|false
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_CLOSED
                || $connection->destinationAddress !== $clientInfo['address']
                || $connection->destinationPort !== $clientInfo['port']
            ) {
                continue;
            }

            return $connection;
        }

        return false;
    }

    /** Find an in-game connection by its world-local tee index. */
    public function findByTee(AbstractWorld $world, int $teeIndex): ?ConnectionSlot
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_INGAME
                && $connection->world() === $world
                && $connection->playerTee()->teeIndex === $teeIndex
            ) {
                return $connection;
            }
        }

        return null;
    }

    public function isWorldFull(AbstractWorld $world): bool
    {
        $connections = array_filter(
            $this->slots,
            fn (ConnectionSlot $slot): bool => $slot->world() === $world && $slot->state !== ConnectionSlot::STATE_CLOSED,
        );

        return count($connections) >= $world->getSlotsLimit();
    }

    /**
     * @return ConnectionSlot[]
     */
    public function getConnections(): array
    {
        return $this->slots;
    }

    public function update(): void
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_WAITING_INIT || $connection->state === ConnectionSlot::STATE_CLOSED) {
                continue;
            }

            if (($reason = $connection->update()) !== null) {
                $connection->closeConnection($reason);
            }
        }

        $this->clearConnections();
    }

    protected function clearConnections(): void
    {
        foreach ($this->slots as $i => $connection) {
            if ($connection->state === ConnectionSlot::STATE_CLOSED) {
                unset($this->slots[$i]);
            }
        }

        $this->slots = array_values($this->slots);
    }
}
