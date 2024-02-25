<?php

namespace Network;

use Network\Chunks\Game\ClStartInfoChunk;
use Network\Chunks\Game\SvMotdChunk;
use Network\Chunks\Game\SvReadyToEnterChunk;
use Network\Chunks\Game\SvTuneParamsChunk;
use Network\Chunks\Game\SvVoteClearOptionsChunk;
use Network\Chunks\System\ConReadyChunk;
use Network\Chunks\System\EnterGameChunk;
use Network\Chunks\System\InfoChunk;
use Network\Chunks\System\InputChunk;
use Network\Chunks\System\MapChangeChunk;
use Network\Chunks\System\ReadyChunk;
use Network\Chunks\System\RequestMapDataChunk;
use Network\Chunks\System\SnapChunk;
use Network\Chunks\System\SnapEmptyChunk;
use Network\Chunks\System\SnapSingleChunk;
use Network\Chunks\UnsupportedChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
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

        $payload = array_slice($data, NetworkParams::PACKET_HEADER_SIZE_DEFAULT);

        if ($flags & Network::PACKETFLAG_COMPRESSION) {
            $payload = NetworkBase::decompressHuffman($payload);
        }

        // Default Packet
        $chunks = static::decodeChunksFromPayload(
            payload: $payload,
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

            $chunkClass = static::matchDecodedChunk($flags, $message);

            if ($chunkClass instanceof UnsupportedChunk) {
                $chunks[] = $chunkClass->setSequence($sequence);
            } else {
                $chunks[] = $chunkClass::make(new RawPayload(array_slice($payload, $pointer + $headerSize + 1, $size - 1))) // +1 to skip the message byte
                    ->setSequence($sequence);
            }

            $pointer += $headerSize + $size; // The +1 CANNOT be added here
        }

        return $chunks;
    }

    protected static function matchDecodedChunk(int $flags, int $message): UnsupportedChunk|string
    {
        return match ($message) {
            // System
            Protocol::INFO       => InfoChunk::class,
            Protocol::MAP_CHANGE => MapChangeChunk::class,
            // Protocol::MAP_DATA => ,
            Protocol::CON_READY        => ConReadyChunk::class,
            Protocol::SNAP             => SnapChunk::class,
            Protocol::SNAPEMPTY        => SnapEmptyChunk::class,
            Protocol::SNAPSINGLE       => SnapSingleChunk::class,
            // Protocol::INPUTTIMING => ,
            // Protocol::RCON_AUTH_STATUS => ,
            // Protocol::RCON_LINE => ,
            Protocol::READY            => ReadyChunk::class,
            Protocol::ENTERGAME        => EnterGameChunk::class,
            Protocol::INPUT            => InputChunk::class,
            Protocol::REQUEST_MAP_DATA => RequestMapDataChunk::class,
            // Protocol::PING => ,
            // Protocol::PING_REPLY => ,
            // Game
            Protocol::SV_MOTD             => SvMotdChunk::class,
            Protocol::SV_TUNEPARAMS       => SvTuneParamsChunk::class,
            Protocol::SV_READYTOENTER     => SvReadyToEnterChunk::class,
            Protocol::SV_VOTECLEAROPTIONS => SvVoteClearOptionsChunk::class,
            Protocol::CL_START_INFO       => ClStartInfoChunk::class,
            default                       => new UnsupportedChunk($flags, $message),
        };
    }
}
