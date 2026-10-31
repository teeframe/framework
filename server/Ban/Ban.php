<?php

namespace TeeFrame\Server\Ban;

class Ban
{
    public function __construct(
        public string $address,
        public int $expiry,
        public string $reason,
    ) {}

    public function isExpired(int $now): bool
    {
        return $this->expiry <= $now;
    }
}
