<?php

class SlotConnection
{
    public int $sequence = 0;

    public int $ack = 0;

    public int $peerAck = 0;

    public bool $remoteClosed = false;

    public int $state = NetConnState::OFFLINE;

    public int $lastSendTime = 0;

    public int $lastRecvTime = 0;

    public int $lastUpdateTime = 0;

    public bool $unknownSequence = false;

    public string $clientAddress = '';

    public int $clientPort = 0;

    public array $stats = [];

    /**
     * Construct method.
     */
    public function __construct()
    {
    }
}
