<?php

namespace Network\Decoder\Concerns;

use Network\Decoder\DecodedPacketChunk;
use Network\Enums\Network;

trait HasPacketDecoder
{
    const HEADER_SIZE_DEFAULT          = 7;
    const HEADER_SIZE_DEFAULT_NO_TOKEN = 3;
    const HEADER_SIZE_CONNLESS         = 6;

    // Size limits
    const MINIMUM_PACKET_SIZE       = 3;
    const MAXIMUM_PACKET_SIZE       = 1400;
    const MINIMUM_PACKET_CHUNK_SIZE = 3;

    public static function decodeFromRaw(string $rawBuffer): static|false
    {
        if (strlen($rawBuffer) < static::MINIMUM_PACKET_SIZE || strlen($rawBuffer) > static::MAXIMUM_PACKET_SIZE) {
            return false; // TOO SMALL || TO BIG
        }

        $data  = array_values(unpack('C*', $rawBuffer));
        $flags = $data[0] >> 4;

        // Connless special case
        if ($flags & Network::PACKETFLAG_CONNLESS) {
            return new static(
                flags: $flags,
                ack: 0,
                numChunks: 0,
                rawPayload: [],
                chunks: [],
            );
        }

        // Any other packet
        $ack        = (($data[0] & 0xF) << 8) | $data[1];
        $numChunks  = $data[2];
        $rawPayload = array_slice($data, static::HEADER_SIZE_DEFAULT_NO_TOKEN);

        if (! ($flags & Network::PACKETFLAG_CONTROL)) {
            $chunks = static::decodeChunksFromPayload($rawPayload, $flags & Network::PACKETFLAG_COMPRESSION);
        }

        return new static(
            flags: $flags,
            ack: $ack,
            numChunks: $numChunks,
            rawPayload: $rawPayload,
            chunks: $chunks ?? [],
        );
    }

    public static function decodeChunksFromPayload(array $payload, bool $isCompressed): array
    {
        $pointer = 0;
        $chunks  = [];

        // TODO: Implement huffman compression

        while (count($payload) > $pointer + static::MINIMUM_PACKET_CHUNK_SIZE) {
            $flags = ($payload[$pointer + 0] >> 6) & 3;
            $size  = (($payload[$pointer + 0] & 0x3F) << 4) | ($payload[$pointer + 1] & 0xF);

            $headerSize = ($flags & Network::CHUNKFLAG_VITAL) ? 3 : 2;

            $sequence = ($headerSize === 3)
                ? (($payload[$pointer + 1] & 0xF0) << 2) | $payload[$pointer + 2]
                : -1;

            $message = $payload[$pointer + $headerSize];
            $message >>= 1;

            if (! ($payload[$pointer + $headerSize] & 1)) {
                $message += 128;
            }

            $chunks[] = new DecodedPacketChunk($flags, $sequence, $message, array_slice($payload, $pointer + $headerSize + 1)); // +1 to skip the message byte
            $pointer += $headerSize + $size; // The +1 CANNOT be added here
        }

        return $chunks;
    }
}
