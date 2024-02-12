<?php

namespace Base;

use Network\Decoder\DecodedPacket;

class SlotConnection
{
    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_ERROR      = 3;

    public int $sequence = 0;

    public int $ack = 0;

    public int $peerAck = 0;

    public bool $remoteClosed = false;

    public int $state = self::STATE_EMPTY;

    public int $lastSendTime = 0;

    public int $lastRecvTime = 0;

    public int $lastUpdateTime = 0;

    public bool $unknownSequence = false;

    public string $clientAddress = '';

    public int $clientPort = 0;

    public array $stats = [];

    public function __construct()
    {
    }

    public function connect()
    {
    }

    public function feed(DecodedPacket $packet)
    {
    }
}
