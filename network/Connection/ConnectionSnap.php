<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Network\SnapItems\AbstractSnapItem;

class ConnectionSnap
{
    /**
     * @param  array<int, AbstractSnapItem>  $snapItems
     * @param  int  $sendTime  Monotonic timestamp in nanoseconds.
     */
    public function __construct(
        protected int $tick,
        protected array $snapItems,
        protected int $sendTime,
    ) {}

    public function getTick(): int
    {
        return $this->tick;
    }

    public function getSendTime(): int
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
