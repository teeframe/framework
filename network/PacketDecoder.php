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
use Network\Chunks\System\SnapSliceChunk;
use Network\Chunks\System\SnapEmptyChunk;
use Network\Chunks\System\SnapSingleChunk;
use Network\Chunks\UnsupportedChunk;
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
        $isResend  = $flags & NetworkBase::PACKET_FLAG_RESEND;

        // TODO: Implement token support

        // Connection-less Message
        if ($flags & NetworkBase::PACKET_FLAG_TYPE_CONNECTION_LESS) {
            return new ConnectionLessMessage(ack: $ack, resend: $isResend);
        }

        // Control Message
        if ($flags & NetworkBase::PACKET_FLAG_TYPE_CONTROL) {
            return new ControlMessage(message: $data[3], extra: NetworkBase::packBuffer(array_slice($data, 4)), ack: $ack, resend: $isResend);
        }

        $payload = array_slice($data, NetworkParams::PACKET_HEADER_SIZE_DEFAULT);

        if ($flags & NetworkBase::PACKET_FLAG_COMPRESSION) {
            $payload = NetworkBase::decompressHuffman($payload);
        }

        // Default Packet
        $chunks = static::decodeChunksFromPayload($payload);

        return new DefaultPacket(chunks: $chunks, ack: $ack, resend: $isResend);
    }

    public static function decodeChunksFromPayload(array $payload): array
    {
        $pointer = 0;
        $chunks  = [];

        // TODO: Refactor this
        while (count($payload) > $pointer + NetworkParams::MINIMUM_PACKET_CHUNK_SIZE) {
            $flags = ($payload[$pointer + 0] >> 6) & 3;
            $size  = (($payload[$pointer + 0] & 0x3F) << 4) | ($payload[$pointer + 1] & 0xF);

            $headerSize = ($flags & NetworkBase::CHUNK_FLAG_VITAL) ? 3 : 2;

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
            NetworkMessages::INFO       => InfoChunk::class,
            NetworkMessages::MAP_CHANGE => MapChangeChunk::class,
            // NetworkMessages::MAP_DATA => ,
            NetworkMessages::CON_READY        => ConReadyChunk::class,
            NetworkMessages::SNAP             => SnapSliceChunk::class,
            NetworkMessages::SNAPEMPTY        => SnapEmptyChunk::class,
            NetworkMessages::SNAPSINGLE       => SnapSingleChunk::class,
            // NetworkMessages::INPUTTIMING => ,
            // NetworkMessages::RCON_AUTH_STATUS => ,
            // NetworkMessages::RCON_LINE => ,
            NetworkMessages::READY            => ReadyChunk::class,
            NetworkMessages::ENTERGAME        => EnterGameChunk::class,
            NetworkMessages::INPUT            => InputChunk::class,
            NetworkMessages::REQUEST_MAP_DATA => RequestMapDataChunk::class,
            // NetworkMessages::PING => ,
            // NetworkMessages::PING_REPLY => ,
            // Game
            NetworkMessages::SV_MOTD             => SvMotdChunk::class,
            NetworkMessages::SV_TUNEPARAMS       => SvTuneParamsChunk::class,
            NetworkMessages::SV_READYTOENTER     => SvReadyToEnterChunk::class,
            NetworkMessages::SV_VOTECLEAROPTIONS => SvVoteClearOptionsChunk::class,
            NetworkMessages::CL_START_INFO       => ClStartInfoChunk::class,
            default                       => new UnsupportedChunk($flags, $message),
        };
    }
}
