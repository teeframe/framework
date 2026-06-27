<?php

namespace TeeFrame\Server\Ban;

/**
 * Holds banned client addresses and their expiry times.
 */
class BanList
{
    /** @var array<string, Ban> keyed by address */
    protected array $bans = [];

    /**
     * Bans an address for the given duration (in seconds).
     */
    public function ban(string $address, int $durationSeconds, string $reason): void
    {
        $this->bans[$address] = new Ban(
            address: $address,
            expiry: time() + $durationSeconds,
            reason: $reason,
        );
    }

    public function isBanned(string $address): bool
    {
        $this->cleanup();

        return isset($this->bans[$address]);
    }

    public function getBan(string $address): ?Ban
    {
        $this->cleanup();

        return $this->bans[$address] ?? null;
    }

    /**
     * Removes expired bans.
     */
    public function cleanup(): void
    {
        $now = time();

        foreach ($this->bans as $address => $ban) {
            if ($ban->isExpired($now)) {
                unset($this->bans[$address]);
            }
        }
    }
}
