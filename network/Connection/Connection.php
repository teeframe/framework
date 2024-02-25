<?php

namespace Network\Connection;

use Network\Packets\AbstractPacket;

abstract class Connection
{
    public string $clientAddress;

    public int $clientPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    protected ChunkHandler $chunkHandler;

    protected SnapHandler $snapHandler;

    public function __construct()
    {
        $this->chunkHandler = new ChunkHandler($this);
        $this->snapHandler  = new SnapHandler($this);

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
        $this->chunks()->flushSentList();
    
        $this->snaps()->resetState();
        $this->snaps()->flushSentList();
    }

    public function chunks(): ChunkHandler
    {
        return $this->chunkHandler;
    }

    public function snaps(): SnapHandler
    {
        return $this->snapHandler;
    }

    abstract public function sendPacket(AbstractPacket $packet): bool;
}