<?php

namespace Network\Encoder;

use Base\Instance;

class PackageEncoder
{
    /**
     * @param  array<int, PackageChunkEncoder>  $chunks
     */
    public function __construct(protected int $flags, protected int $ack, protected array $chunks)
    {
    }

    public function send(string $address, int $port)
    {
        $encodedData = implode('', array_map('chr', $this->encode()));

        Instance::$server->sendto($address, $port, $encodedData);
    }

    protected function encode()
    {
        // $this->flags &= ~NetPacketFlag::COMPRESSION;

        $header    = [];
        $header[0] = (($this->flags << 2) & 0xFC) | (($this->ack >> 8) & 0x3);
        $header[1] = $this->ack & 0xFF;
        $header[2] = count($this->chunks);

        $payload = [];
        foreach ($this->chunks as $chunk) {
            $payload = [...$payload, ...$chunk->encode()];
        }

        return [...$header, ...$payload];
    }
}
