<?php

namespace Network;

class Limits
{
    const PACKET_HEADER_SIZE_DEFAULT = 3;
    const PACKET_HEADER_SIZE_CONNECTION_LESS = 6;

    const MINIMUM_PACKET_SIZE       = 3;
    const MAXIMUM_PACKET_SIZE       = 1400;

    const MINIMUM_PACKET_CHUNK_SIZE = 3;

    const MINIMUM_TICK = 0;
    const MAXIMUM_TICK = 0x6FFFFFFF;

    const MAXIMUM_ACK = 1024;
    const MAXIMUM_CHUNKS = 255;

    const MAXIMUM_SNAP_SLICES = 64;
    const MAXIMUM_SNAP_PAYLOAD_SIZE = 900;
}