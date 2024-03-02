<?php

namespace Server;

use TeeFrame\Server\Connection\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionHandler
{
    /**
     * @var ConnectionSlot[]
     */
    protected array $slots = [];

    public function __construct(protected int $slotsLimit)
    {
    }

    public function startNew(AbstractSocket $socket)
    {
        if (count($this->slots) >= $this->slotsLimit) {
            throw new \RuntimeException('Connection slots limit reached');
        }

        $slot = new ConnectionSlot(count($this->slots), $socket);

        $this->slots[] = $slot;
    }

    /**
     * @return ConnectionSlot[]
     */
    public function getConnections(): array
    {
        return $this->slots;
    }
}