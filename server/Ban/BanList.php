<?php

namespace TeeFrame\Server\Ban;

use TeeFrame\Core\IpAddress;
use TeeFrame\Server\Console;

class BanList
{
    /** @var array<string, Ban> keyed by address */
    protected array $bans = [];

    public function ban(string $address, ?int $durationSeconds, string $reason): void
    {
        $address              = IpAddress::normalize($address) ?? $address;
        $this->bans[$address] = new Ban(
            address: $address,
            expiry: $durationSeconds === null ? null : time() + $durationSeconds,
            reason: $reason,
        );
        Console::info('ban', 'Banned Address='.$address.' Duration='.($durationSeconds === null ? 'permanent' : $durationSeconds.'s').' Reason='.$reason);
    }

    public function clear(): void
    {
        $count      = count($this->bans);
        $this->bans = [];
        if ($count > 0) {
            Console::info('ban', 'Cleared bans Count='.$count);
        }
    }

    public function unban(string $address): void
    {
        $address = IpAddress::normalize($address) ?? $address;
        if (isset($this->bans[$address])) {
            unset($this->bans[$address]);
            Console::info('ban', 'Unbanned Address='.$address);
        }
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

        return isset($this->bans[IpAddress::normalize($address) ?? $address]);
    }

    public function getBan(string $address): ?Ban
    {
        $this->cleanup();

        return $this->bans[IpAddress::normalize($address) ?? $address] ?? null;
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
                Console::debug('ban', 'Ban expired Address='.$address);
            }
        }
    }
}
