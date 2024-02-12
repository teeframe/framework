<?php

namespace Network\Encoder;

class PackageEncoder
{
    /**
     * @param  array<int, PackageChunkEncoder>  $chunks
     */
    public function __construct(protected int $flags, protected int $ack, protected int $numChunks, protected array $chunks)
    {
    }

    public function send(string $address, int $port)
    {

    }
}
