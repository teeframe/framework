<?php

namespace Network\Decoder\Concerns;

use Network\Decoder\DecodedPacketChunk;
use Network\Enums\Network;

trait HasPacketDecoder
{
    const HEADER_SIZE_DEFAULT       = 7;
    const HEADER_SIZE_CONNLESS      = 6;
    const HEADER_SIZE_WITHOUT_TOKEN = 3;

    public static function decodeFromRaw(string $rawBuffer): static|false
    {
        if (strlen($rawBuffer) < 3 || strlen($rawBuffer) > 1400) {
            return false; // TOO SMALL || TO BIG
        }

        $data  = array_values(unpack('C*', $rawBuffer));
        $flags = $data[0] >> 2;

        // Connless special case
        if ($flags & Network::PACKETFLAG_CONNLESS) {
            return new static(
                flags: $flags,
                ack: 0,
                numChunks: 0,
                rawPayload: [],
                chunks: [],
                // dataSize: count($data) - self::HEADER_SIZE_CONNLESS,
            );
        }

        // Any other packet
        $ack        = ($data[0] & 0b11) << 8 | $data[1];
        $numChunks  = $data[2];
        $rawPayload = array_slice($data, static::HEADER_SIZE_WITHOUT_TOKEN);

        return new static(
            flags: $flags,
            ack: $ack,
            numChunks: $numChunks,
            rawPayload: $rawPayload,
            chunks: static::decodeChunksFromPayload($rawPayload),
            // dataSize: count($data) - self::HEADER_SIZE_DEFAULT // TODO: Verify if this is correct even without token
        );
    }

    public static function decodeChunksFromPayload(array $payload): array
    {
        $pointer = 0;
        $chunks  = [];

        // TODO: handle sequence stuff

        do {
            $flags = ($payload[$pointer + 0] >> 6) & 3;
            $size  = (($payload[$pointer + 0] & 0x3F) << 4) | ($payload[$pointer + 1] & 0xF);

            $headerSize = ($flags & Network::CHUNKFLAG_VITAL) ? 3 : 2;

            $sequence = ($headerSize === 3)
                ? (($payload[$pointer + 1] & 0xF0) << 2) | $payload[$pointer + 2]
                : -1;

            $message = $payload[$pointer + $headerSize];
            $message >>= 1;

            // +1 to skip the message byte

            $chunks[] = new DecodedPacketChunk($flags, $sequence, $message, array_slice($payload, $pointer + $headerSize + 1));
            $pointer += $headerSize + $size + 1;
        } while ($pointer < count($payload));

        return $chunks;
    }
}
