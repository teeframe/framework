<?php

namespace Network\Encoder;

use Base\Instance;
use Helpers\IsMakeable;
use Network\Enums\Network;

class PackageEncoder
{
    use IsMakeable;

    /**
     * @param  array<int, PackageChunkEncoder>  $chunks
     */
    public function __construct(protected int $flags, protected int $ack = 0, protected array $chunks = [])
    {
    }

    public function send(string $address, int $port)
    {
        $encodedData = implode('', array_map('chr', $this->encode()));

        Instance::$server->sendto($address, $port, $encodedData);
    }

    protected function encode()
    {
        if (false) {
            $this->flags &= ~Network::PACKETFLAG_COMPRESSION; // TODO: Implement this
        }

        $header    = [];
        $header[0] = (($this->flags << 4) & 0xF0) | (($this->ack >> 8) & 0xF);
        $header[1] = $this->ack & 0xFF;
        $header[2] = count($this->chunks);

        $payload = [];
        foreach ($this->chunks as $chunk) {
            $payload = [...$payload, ...$chunk->encode()];
        }

        return [...$header, ...$payload];
    }
}
