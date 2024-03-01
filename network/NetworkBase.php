<?php

namespace Network;

use Network\Huffman\Huffman;

// TODO: Better type this

class NetworkBase
{
    // Packet Flags
    const PACKET_FLAG_TYPE_DEFAULT         = 0;
    const PACKET_FLAG_TYPE_CONTROL         = 1;
    const PACKET_FLAG_TYPE_CONNECTION_LESS = 2;
    const PACKET_FLAG_RESEND               = 4;
    const PACKET_FLAG_COMPRESSION          = 8;

    // Chunk Flags
    const CHUNK_FLAG_VITAL  = 1;
    const CHUNK_FLAG_RESEND = 2;

    protected static ?Huffman $huffmanInstance = null;

    public static function compressHuffman(array $buffer): array
    {
        if (! self::$huffmanInstance) {
            self::$huffmanInstance = new Huffman;
        }

        return self::$huffmanInstance->compress($buffer);
    }

    public static function decompressHuffman(array $buffer): array
    {
        if (! self::$huffmanInstance) {
            self::$huffmanInstance = new Huffman;
        }

        return self::$huffmanInstance->decompress($buffer);
    }

    /**
     * This handles the difference between PHP and C++ int.
     * The difference start to get some trouble if the value is greater than 0x7FFFFFFF (2147483647)
     * After this number, C++ will invert the number to negative, while PHP will just add +1 correctly
     */
    public static function toInt32(int $value): int
    {
        return $value & 0x80000000 ? -((~$value & 0xFFFFFFFF) + 1) : $value;
    }

    public static function packBuffer(array $buffer): string
    {
        return implode('', array_map('chr', $buffer));
    }

    public static function unpackBuffer(string $packet): array
    {
        $result = unpack('C*', $packet);

        if ($result === false) {
            return [];
        }

        return array_values($result);
    }

    public static function isSequenceInBackroom(int $sequence, int $ack): bool
    {
        $bottom = ($ack - NetworkParams::MAXIMUM_ACK_NUMBER / 2);

        if ($bottom < 0) {
            if ($sequence <= $ack || $sequence > ($bottom + NetworkParams::MAXIMUM_ACK_NUMBER)) {
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
