<?php

namespace Network;

use Network\Chunks\Game\SvReadyToEnterChunk;
use Network\Chunks\Game\SvVoteClearOptionsChunk;
use Network\Chunks\System\ConReadyChunk;
use Network\Chunks\System\MapChangeChunk;
use Network\Chunks\System\SnapChunk;
use Network\Chunks\System\SnapEmptyChunk;
use Network\Chunks\System\SnapSingleChunk;
use Network\Chunks\Game\SvMotdChunk;
use Network\Chunks\Game\SvTuneParamsChunk;
use Network\Enums\Network;
use Network\NetworkParams;
use Network\NetworkBase;
use Network\Packets\AbstractPacket;
use Network\Packets\ConnectionLessMessage;
use Network\Packets\ControlMessage;
use Network\Packets\DefaultPacket;

class PacketDecoder
{
    public static function decodeFromRaw(string $rawBuffer): AbstractPacket|false
    {
        if (strlen($rawBuffer) < NetworkParams::MINIMUM_PACKET_SIZE || strlen($rawBuffer) > NetworkParams::MAXIMUM_PACKET_SIZE) {
            return false;
        }

        $data      = array_values(NetworkBase::unpackBuffer($rawBuffer));
        $flags     = $data[0] >> 4;
        $ack       = (($data[0] & 0xF) << 8) | $data[1];
        $numChunks = $data[2];

        // TODO: Implement token support

        // Connection-less Message
        if ($flags & Network::PACKETFLAG_CONNLESS) {
            return new ConnectionLessMessage(ack: $ack);
        }

        // Control Message
        if ($flags & Network::PACKETFLAG_CONTROL) {
            return new ControlMessage(message: $data[3], extra: NetworkBase::packBuffer(array_slice($data, 4)), ack: $ack);
        }

        // Default Packet
        $chunks = static::decodeChunksFromPayload(
            payload: array_slice($data, NetworkParams::PACKET_HEADER_SIZE_DEFAULT), 
            isCompressed: $flags & Network::PACKETFLAG_COMPRESSION
        );

        return new DefaultPacket(chunks: $chunks, ack: $ack);
    }

    public static function decodeChunksFromPayload(array $payload, bool $isCompressed): array
    {
        $pointer = 0;
        $chunks  = [];

        // TODO: Implement huffman compression

        // TODO: Refactor this
        while (count($payload) > $pointer + NetworkParams::MINIMUM_PACKET_CHUNK_SIZE) {
            $flags = ($payload[$pointer + 0] >> 6) & 3;
            $size  = (($payload[$pointer + 0] & 0x3F) << 4) | ($payload[$pointer + 1] & 0xF);

            $headerSize = ($flags & Network::CHUNKFLAG_VITAL) ? 3 : 2;

            $sequence = ($headerSize === 3)
                ? (($payload[$pointer + 1] & 0xF0) << 2) | $payload[$pointer + 2]
                : -1;

            $message = $payload[$pointer + $headerSize] >> 1;

            if (! ($payload[$pointer + $headerSize] & 1)) {
                $message += 128;
            }

            $chunkClass = static::matchDecodedChunk($message);

            $chunks[] = $chunkClass::make(array_slice($payload, $pointer + $headerSize + 1, $size - 1)) // +1 to skip the message byte
                ->setSequence($sequence);

            $pointer += $headerSize + $size; // The +1 CANNOT be added here
        }

        return $chunks;
    }

    protected static function matchDecodedChunk(int $message): string
    {
        return match($message) {
            // System
            // 1 => ,
            2 => MapChangeChunk::class,
            // 3 => ,
            4 => ConReadyChunk::class,
            5 => SnapChunk::class,
            6 => SnapEmptyChunk::class,
            7 => SnapSingleChunk::class,
            // Game
            128 + 1 => SvMotdChunk::class,
            128 + 6 => SvTuneParamsChunk::class,
            128 + 8 => SvReadyToEnterChunk::class,
            128 + 10 => SvVoteClearOptionsChunk::class,
            // TODO: Handle unknown messages
        };
    }
}