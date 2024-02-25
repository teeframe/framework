<?php

namespace Network\Connection;

use Network\SnapItems\AbstractSnapItem;

class ConnectionSnap
{
    /**
     * @param array<int, AbstractSnapItem> $snapItems
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
     * @return array<int, AbstractSnapItem>
     */
    public function getSnapItems(): array
    {
        return $this->snapItems;
    }
}