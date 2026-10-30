<?php

namespace Tests\Support;

use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\Packets\AbstractPacket;

class RecordingConnection extends AbstractConnection
{
    /** @var list<AbstractPacket> */
    public array $sentPackets = [];

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        $this->sentPackets[] = $packet;

        return true;
    }

    protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void {}

    protected function handleConnectionOutOfSequence(int $sequence, int $ack): void {}
}
