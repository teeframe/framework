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

    public function __construct(protected TickHandler $tickHandler)
    {
    }

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
     * @param array{address: string, port: int} $clientInfo
     */
    public function tryToMatch(array $clientInfo): ConnectionSlot|false
    {
        foreach ($this->slots as $connection) {
            if ($connection->destinationAddress !== $clientInfo['address'] || $connection->destinationPort !== $clientInfo['port']) {
                continue;
            }

            return $connection;
        }

        return false;
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
