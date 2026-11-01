<?php

namespace TeeFrame\Server\Ban;

class BanList
{
    /** @var array<string, Ban> keyed by address */
    protected array $bans = [];

    public function ban(string $address, int $durationSeconds, string $reason): void
    {
        $this->bans[$address] = new Ban(
            address: $address,
            expiry: time() + $durationSeconds,
            reason: $reason,
        );
    }

    public function unban(string $address): void
    {
        unset($this->bans[$address]);
    }

    /** @return array<string, Ban> */
    public function getBans(): array
    {
        $this->cleanup();

        return $this->bans;
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
