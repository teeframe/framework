<?php

namespace TeeFrame\Core;

class Utf8
{
    public static function truncateBytes(string $text, int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('The byte limit must not be negative');
        }
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $length = $maxBytes;
        // If the first excluded byte is a continuation, omit its partial character.
        while ($length > 0 && (ord($text[$length]) & 0xC0) === 0x80) {
            $length--;
        }

        return substr($text, 0, $length);
    }
}
