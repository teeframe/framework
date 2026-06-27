<?php

namespace TeeFrame\Server\Ban;

/**
 * A ban entry for a client address.
 */
class Ban
{
    public function __construct(
        public string $address,
        public int $expiry,
        public string $reason,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $this->expiry <= $now;
    }
}
