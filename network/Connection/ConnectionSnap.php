<?php

namespace Network\Connection;

use Network\Encoder\SnapItemEncoder;

class ConnectionSnap
{
    /**
     * @param array<int, SnapItemEncoder> $snapItems
     */
    public function __construct(
        protected int $tick,
        protected array $snapItems
    ) {
    }

    public function getTick(): int
    {
        return $this->tick;
    }
    
    /**
     * @return array<int, SnapItemEncoder>
     */
    public function getSnapItems(): array
    {
        return $this->snapItems;
    }
}