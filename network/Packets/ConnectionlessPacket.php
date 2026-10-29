<?php

namespace TeeFrame\Network\Packets;

use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;

class ConnectionlessPacket extends AbstractPacket
{
    public function __construct(string $data, protected ?int $extraToken = null)
    {
        parent::__construct(
            flags: NetworkBase::PACKET_FLAG_TYPE_CONNECTION_LESS,
            ack: 0,
            payload: NetworkBase::unpackBuffer($data),
        );
    }

    public function getData(): string
    {
        return NetworkBase::packBuffer($this->payload);
    }

    public function getExtraToken(): ?int
    {
        return $this->extraToken;
    }

    protected function encode(): array
    {
        $header = $this->extraToken === null
            ? array_fill(0, NetworkParams::PACKET_HEADER_SIZE_CONNECTION_LESS, 0xFF)
            : NetworkBase::unpackBuffer('xe'.pack('n', $this->extraToken)."\0\0");

        return [...$header, ...$this->payload];
    }
}
