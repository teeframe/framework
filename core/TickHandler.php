<?php

namespace TeeFrame\Core;

use TeeFrame\Network\NetworkParams;

class TickHandler
{
    protected int $currentTickStartTime;

    public function __construct(protected int $currentTick = 0)
    {
        $this->startTick();
    }

    public function startTick(): void
    {
        $this->currentTickStartTime = $this->getTime();
    }

    public function getTimeUntilTick(int $tick): int
    {
        $tickDuration = intdiv(1_000_000_000, NetworkParams::TICKS_PER_SECOND);
        $deadline     = $this->currentTickStartTime + ($tick - $this->currentTick) * $tickDuration;

        return $deadline - $this->getTime();
    }

    public function getTime(): int
    {
        return hrtime(true);
    }

    public function getCurrentTick(): int
    {
        return $this->currentTick;
    }

    public function advanceTick(): void
    {
        $this->currentTick++;
        $this->currentTickStartTime += intdiv(1_000_000_000, NetworkParams::TICKS_PER_SECOND);
    }
}
