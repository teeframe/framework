<?php

namespace Network\Packets;

use Network\Enums\Network;
use Network\NetworkBase;

class ControlMessage extends AbstractPacket
{
    public function __construct(int $message, string $extra = '', int $ack = 0, bool $resend = false)
    {
        $flags = Network::PACKETFLAG_CONTROL;

        if ($resend) {
            $flags |= Network::PACKETFLAG_RESEND;
        }

        $payload = [$message];

        if ($extra !== '') {
            $payload = [...$payload, ...NetworkBase::unpackBuffer($extra), 0];
        }

        parent::__construct(flags: $flags, ack: $ack, payload: [$message, ...$payload]);
    }

    public function getControlMessage(): int
    {
        return $this->payload[0];
    }

    public function getControlMessageExtra(): string
    {
        return NetworkBase::packBuffer(array_slice($this->payload, 1));
    }
}