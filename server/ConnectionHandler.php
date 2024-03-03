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
        if (count($this->slots) >= $this->slotsLimit) {
            return false;
        }

        $this->slots[] = $slot = new ConnectionSlot(count($this->slots), $socket, $world);

        return $slot;
    }

    public function tryToMatch(array $clientInfo): ConnectionSlot|false
    {
        foreach ($this->slots as $connection) {
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

    /**
     * @return ConnectionSlot[]
     */
    public function getConnections(): array
    {
        return $this->slots;
    }
}
