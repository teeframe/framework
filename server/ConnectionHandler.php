<?php

namespace TeeFrame\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionHandler
{
    use Concerns\HasHandshakeHandler;

    /**
     * @var ConnectionSlot[]
     */
    protected array $slots = [];

    public function __construct(protected int $slotsLimit)
    {
    }

    public function startNew(AbstractSocket $socket, AbstractWorld $world): ConnectionSlot|false
    {
        $this->clearConnections();

        if (count($this->slots) >= $this->slotsLimit) {
            return false;
        }

        $this->slots[] = $slot = new ConnectionSlot($socket, $world);

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
