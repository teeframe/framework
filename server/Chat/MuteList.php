<?php

namespace TeeFrame\Server\Chat;

use TeeFrame\Core\IpAddress;

class MuteList
{
    /** @var array<string, array{expiry: int, reason: string}> */
    protected array $mutes = [];

    public function mute(string $address, int $seconds, string $reason): void
    {
        if ($seconds < 1 || $seconds > 86400) {
            throw new \InvalidArgumentException('Mute duration must be between 1 and 86400 seconds');
        }
        $this->mutes[$this->normalizeAddress($address)] = ['expiry' => time() + $seconds, 'reason' => $reason];
    }

    public function unmute(string $address): void
    {
        unset($this->mutes[$this->normalizeAddress($address)]);
    }

    /** @return array{expiry: int, reason: string}|null */
    public function getMute(string $address): ?array
    {
        return $this->getMutes()[$this->normalizeAddress($address)] ?? null;
    }

    /** @return array<string, array{expiry: int, reason: string}> */
    public function getMutes(): array
    {
        $now         = time();
        $this->mutes = array_filter($this->mutes, fn (array $mute): bool => $mute['expiry'] > $now);

        return $this->mutes;
    }

    protected function normalizeAddress(string $address): string
    {
        return IpAddress::normalize($address) ?? throw new \InvalidArgumentException('Expected an IP address');
    }
}
