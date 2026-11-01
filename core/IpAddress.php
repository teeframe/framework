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

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }
}
