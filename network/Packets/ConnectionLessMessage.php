<?php

namespace Network\Packets;

use Network\Enums\Network;

class ConnectionLessMessage extends AbstractPacket
{
    public function __construct(int $ack = 0, bool $resend = false)
    {
        $flags = Network::PACKETFLAG_CONNLESS;

        if ($resend) {
            $flags |= Network::PACKETFLAG_RESEND;
        }

        parent::__construct(flags: $flags, ack: $ack, payload: []);
    }
}