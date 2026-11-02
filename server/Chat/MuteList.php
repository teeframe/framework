<?php

namespace TeeFrame\Server\Chat;

use TeeFrame\Core\IpAddress;
use TeeFrame\Server\Console;

class MuteList
{
    /** @var array<string, array{expiry: int, reason: string}> */
    protected array $mutes = [];

    public function mute(string $address, int $seconds, string $reason): void
    {
        if ($seconds < 1 || $seconds > 86400) {
            throw new \InvalidArgumentException('Mute duration must be between 1 and 86400 seconds');
        }

        $address               = $this->normalizeAddress($address);
        $this->mutes[$address] = ['expiry' => time() + $seconds, 'reason' => $reason];
        Console::info('mute', 'Muted Address='.$address.' Duration='.$seconds.'s Reason='.$reason);
    }

    public function unmute(string $address): void
    {
        $address = $this->normalizeAddress($address);

        if (isset($this->mutes[$address])) {
            unset($this->mutes[$address]);
            Console::info('mute', 'Unmuted Address='.$address);
        }
    }

    /** @return array{expiry: int, reason: string}|null */
    public function getMute(string $address): ?array
    {
        return $this->getMutes()[$this->normalizeAddress($address)] ?? null;
    }

    /** @return array<string, array{expiry: int, reason: string}> */
    public function getMutes(): array
    {
        $now = time();

        foreach ($this->mutes as $address => $mute) {
            if ($mute['expiry'] <= $now) {
                unset($this->mutes[$address]);
                Console::debug('mute', 'Mute expired Address='.$address);
            }
        }

        return $this->mutes;
    }

    protected function normalizeAddress(string $address): string
    {
        return IpAddress::normalize($address) ?? throw new \InvalidArgumentException('Expected an IP address');
    }
}
