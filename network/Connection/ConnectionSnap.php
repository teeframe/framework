<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Network\SnapItems\AbstractSnapItem;

class ConnectionSnap
{
    /**
     * @param  array<int, AbstractSnapItem>  $snapItems
     */
    public function __construct(
        protected int $tick,
        protected array $snapItems,
        protected float $sendTime,
    ) {
    }

    public function getTick(): int
    {
        return $this->tick;
    }

    public function getSendTime(): float
    {
        return $this->sendTime;
    }

    /**
     * @return array<int, AbstractSnapItem>
     */
    public function getSnapItems(): array
    {
        return $this->snapItems;
    }
}
