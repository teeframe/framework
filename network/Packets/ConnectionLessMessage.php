<?php

namespace Network\Packets;

use Network\NetworkBase;

class ConnectionLessMessage extends AbstractPacket
{
    public function __construct(int $ack = 0, bool $resend = false)
    {
        $flags = NetworkBase::PACKET_FLAG_TYPE_CONNECTION_LESS;

        if ($resend) {
            $flags |= NetworkBase::PACKET_FLAG_RESEND;
        }

        parent::__construct(flags: $flags, ack: $ack, payload: []);
    }
}
