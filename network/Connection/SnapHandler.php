<?php

namespace Network\Connection;

use Network\Encoder\SnapItemEncoder;

class SnapHandler
{
    protected int $lastAckedTick = -1;

    public function __construct(
        protected Connection $connection
    ) {
    }

    public function setLastAckedTick(int $tick): void
    {
        $this->lastAckedTick = $tick;
    }

    /**
     * @param array<int, SnapItemEncoder> $items
     */
    public function sendSnapItems(int $currentTick, array $items): void
    {
        $deltaTick = $currentTick - $this->lastAckedTick;
    }
}