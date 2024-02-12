<?php

namespace Network\Encoder;

class PackageEncoder
{
    public function __construct(protected int $ack, protected int $numChunks)
    {
    }

    public function send()
    {
    }
}
