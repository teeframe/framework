<?php

namespace TeeFrame\Network;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\Game\SvReadyToEnterChunk;
use TeeFrame\Network\Chunks\Game\SvTuneParamsChunk;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;
use TeeFrame\Network\Chunks\System\ConReadyChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\System\RequestMapDataChunk;
use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;

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
        $isResend  = (bool) ($flags & NetworkBase::PACKET_FLAG_RESEND);

        // TODO: Implement token support

        // Connection-less Message
        if ($flags & NetworkBase::PACKET_FLAG_TYPE_CONNECTION_LESS) {
            return false;
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

    /**
     * @param int[] $payload
     * @return AbstractChunk[]
     */
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

            $chunkClass = static::matchDecodedChunk($flags, $message, (bool) ($payload[$pointer + $headerSize] & 1));

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

    protected static function matchDecodedChunk(int $flags, int $message, bool $isSystem): UnsupportedChunk|string
    {
        if ($isSystem) {
            return match ($message) {
                NetworkMessages::INFO       => InfoChunk::class,
                NetworkMessages::MAP_CHANGE => MapChangeChunk::class,
                // NetworkMessages::MAP_DATA => ,
                NetworkMessages::CON_READY  => ConReadyChunk::class,
                NetworkMessages::SNAP       => SnapSliceChunk::class,
                NetworkMessages::SNAPEMPTY  => SnapEmptyChunk::class,
                NetworkMessages::SNAPSINGLE => SnapSingleChunk::class,
                // NetworkMessages::INPUTTIMING => ,
                // NetworkMessages::RCON_AUTH_STATUS => ,
                // NetworkMessages::RCON_LINE => ,
                NetworkMessages::READY            => ReadyChunk::class,
                NetworkMessages::ENTERGAME        => EnterGameChunk::class,
                NetworkMessages::INPUT            => InputChunk::class,
                NetworkMessages::REQUEST_MAP_DATA => RequestMapDataChunk::class,
                // NetworkMessages::PING => ,
                // NetworkMessages::PING_REPLY => ,
                default => new UnsupportedChunk($message, $flags, true),
            };
        }

        return match ($message) {
            NetworkMessages::SV_CHAT             => SvChatChunk::class,
            NetworkMessages::SV_MOTD             => SvMotdChunk::class,
            NetworkMessages::SV_TUNEPARAMS       => SvTuneParamsChunk::class,
            NetworkMessages::SV_READYTOENTER     => SvReadyToEnterChunk::class,
            NetworkMessages::SV_EMOTICON         => SvEmoticonChunk::class,
            NetworkMessages::SV_VOTECLEAROPTIONS => SvVoteClearOptionsChunk::class,
            NetworkMessages::CL_SAY              => ClSayChunk::class,
            NetworkMessages::CL_START_INFO       => ClStartInfoChunk::class,
            NetworkMessages::CL_EMOTICON         => ClEmoticonChunk::class,
            default                              => new UnsupportedChunk($message, $flags, false),
        };
    }
}
