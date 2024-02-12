<?php

namespace Helpers;

class IntegerHelper
{
    public static function pack(int $value): array
    {
        $result = [];
        $sign   = $value < 0 ? 1 : 0;
        $value ^= -$sign; // if(sign) *i = ~(*i)

        $result[] = ($sign << 6) | ($value & 0x3F);
        $value >>= 6;

        while ($value != 0) {
            $result[] = 0x80 | ($value & 0x7F);
            $value >>= 7;
        }

        return $result;
    }

    public static function unpack(array $data): array
    {
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
