<?php

namespace Network;

class NetworkBase
{
    public static function isSequenceInBackroom(int $sequence, int $ack): bool
    {
        $bottom = ($ack - Limits::MAXIMUM_ACK / 2);

        if ($bottom < 0) {
            if ($sequence <= $ack || $sequence > ($bottom + Limits::MAXIMUM_ACK)) {
                return true;
            }

            return false;
        }

        if ($sequence <= $ack && $sequence > $bottom) {
            return true;
        }

        return false;
    }

    public static function packInt(int $value): array
    {
        // TODO: Refactor this

        $pointer = 0;
        $result  = [];

        $result[$pointer] = ($value >> 25) & 0x40; // set sign bit if i<0
        $value            = $value ^ ($value >> 31); // if(i<0) i = ~i

        $result[$pointer] |= $value & 0x3F; // pack 6bit into dst
        $value >>= 6; // discard 6 bits

        if ($value) {
            $result[$pointer] |= 0x80; // set extend bit
            while (1) {
                $pointer++;
                $result[$pointer] = $value & (0x7F); // pack 7bit
                $value >>= 7; // discard 7 bits
                $result[$pointer] |= ((int) ($value !== 0)) << 7; // set extend bit (may branch)
                if ($value === 0) {
                    break;
                }
            }
        }

        $pointer++;

        return $result;
    }

    public static function unpackInt(array $data): array
    {
        // TODO: Refactor this

        $pointer = 0;

        $sign   = ($data[$pointer] >> 6) & 1;
        $result = $data[$pointer]        & 0x3F;

        do {
            if (! ($data[$pointer] & 0x80)) {
                break;
            }
            $pointer++;
            $result |= ($data[$pointer] & 0x7F) << 6;

            if (! ($data[$pointer] & 0x80)) {
                break;
            }
            $pointer++;
            $result |= ($data[$pointer] & 0x7F) << (6 + 7);

            if (! ($data[$pointer] & 0x80)) {
                break;
            }
            $pointer++;
            $result |= ($data[$pointer] & 0x7F) << (6 + 7 + 7);

            if (! ($data[$pointer] & 0x80)) {
                break;
            }
            $pointer++;
            $result |= ($data[$pointer] & 0x7F) << (6 + 7 + 7 + 7);
        } while (0);

        $pointer++;
        $result ^= -$sign; // if(sign) *i = ~(*i)

        return [$result, $pointer];
    }
}
