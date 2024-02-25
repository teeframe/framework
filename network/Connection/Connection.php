<?php

namespace Network\Connection;

class Connection
{
    public string $clientAddress;

    public int $clientPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    protected ChunkHandler $chunkHandler;

    public function __construct()
    {
        $this->chunkHandler = new ChunkHandler($this);

        $this->reset();
    }

    public function reset(): void
    {
        $this->clientAddress  = '';
        $this->clientPort     = 0;
        $this->sequence       = 0;
        $this->ack            = 0;
        $this->peerAck        = 0;

        $this->chunks()->flushQueue();
    }

    public function chunks(): ChunkHandler
    {
        return $this->chunkHandler;
    }
}