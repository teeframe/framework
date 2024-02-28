<?php

namespace Network\Packets;

use Network\NetworkBase;

class ControlMessage extends AbstractPacket
{
    public function __construct(protected int $message, protected string $extra = '', int $ack = 0, bool $resend = false)
    {
        $flags = NetworkBase::PACKET_FLAG_TYPE_CONTROL;

        if ($resend) {
            $flags |= NetworkBase::PACKET_FLAG_RESEND;
        }

        $payload = [$message];

        if ($extra !== '') {
            $payload = [...$payload, ...NetworkBase::unpackBuffer($extra), 0];
        }

        parent::__construct(flags: $flags, ack: $ack, payload: [$message, ...$payload]);
    }

    public function getControlMessage(): int
    {
        return $this->message;
    }

    public function getControlMessageExtra(): string
    {
        return $this->extra;
    }
}
