<?php

namespace Network;

class NetworkParams
{
    // Packet Header
    const PACKET_HEADER_SIZE_DEFAULT         = 3;
    const PACKET_HEADER_SIZE_CONNECTION_LESS = 6;

    // Packet Header
    const MINIMUM_PACKET_SIZE                = 3;
    const MAXIMUM_PACKET_SIZE                = 1400;
    const MINIMUM_PACKET_CHUNK_SIZE          = 3;

    // Tick
    const MINIMUM_TICK                       = 0;
    const MAXIMUM_TICK                       = 0x6FFFFFFF;

    // Ack & Chunk
    const MAXIMUM_ACK                        = 1024;
    const MAXIMUM_CHUNKS                     = 255;

    // Snap
    const MAXIMUM_SNAP_SLICES                = 64;
    const MAXIMUM_SNAP_PAYLOAD_SIZE          = 900;
}
