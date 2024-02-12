<?php

namespace Network\Enums;

class Network
{
    // Packet Flags
    const PACKETFLAG_CONTROL  = 1;
    const PACKETFLAG_CONNLESS = 2;
    const PACKETFLAG_RESEND   = 4;
    // const PACKETFLAG_COMPRESSION = 8; // Not-implemented

    // Control Messages
    const CTRLMSG_KEEPALIVE     = 0;
    const CTRLMSG_CONNECT       = 1;
    const CTRLMSG_CONNECTACCEPT = 2;
    const CTRLMSG_CLOSE         = 4;
    // const CTRLMSG_ACCEPT        = 3; // Unused?

    // Chunks
    const CHUNKFLAG_VITAL  = 1;
    const CHUNKFLAG_RESEND = 2;
}
