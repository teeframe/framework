<?php

namespace TeeFrame\Game\Core;

class TickHandler
{
    public function __construct(protected int $currentTick = 0)
    {
    }

    public function get(): int
    {
        return $this->currentTick;
    }

    public function do(): void
    {
        $this->currentTick++;
    }
}