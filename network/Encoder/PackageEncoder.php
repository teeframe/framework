<?php

namespace Network\Encoder;

use Base\Server\ServerInstance;
use Network\Enums\Network;

class PackageEncoder
{
    /**
     * @param  array<int, PackageChunkEncoder>  $chunks
     */
    public function __construct(
        protected int $flags,
        protected int $ack = 0,
        protected array $chunks = [],
        protected array $extraPayload = []
    ) {
    }

    public static function makeControlMessage(int $message, string $extra = ''): static
    {
        $extraPayload = [$message];

        if ($extra !== '') {
            $extraPayload = [...$extraPayload, ...unpack('C*', $extra), 0];
        }

        return new static(Network::PACKETFLAG_CONTROL, 0, [], $extraPayload);
    }

    public function send(string $address, int $port): bool
    {
        $encodedData = implode('', array_map('chr', $this->encode()));

        return ServerInstance::sendto($address, $port, $encodedData);
    }

    protected function encode(): array
    {
        if (false) {
            $this->flags &= ~Network::PACKETFLAG_COMPRESSION; // TODO: Implement huffman compression
        }

        $header    = [];
        $header[0] = (($this->flags << 4) & 0xF0) | (($this->ack >> 8) & 0xF);
        $header[1] = $this->ack & 0xFF;
        $header[2] = count($this->chunks);

        $payload = [];
        foreach ($this->chunks as $chunk) {
            $payload = [...$payload, ...$chunk->encode()];
        }

        return [...$header, ...$payload, ...$this->extraPayload];
    }
}
