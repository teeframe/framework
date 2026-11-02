<?php

namespace TeeFrame\Core;

class IpAddress
{
    public static function normalize(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);

        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }
}
