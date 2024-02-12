<?php

namespace Base;

use Network\Decoder\DecodedPacket;
use Network\Encoder\PackageEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SlotConnection
{
    const STATE_EMPTY      = 0;
    const STATE_CONNECTING = 1;
    const STATE_LOADING    = 2;
    const STATE_ERROR      = 3;

    public string $clientAddress = '';

    public int $clientPort = 0;

    public int $sequence = 0;

    public int $ack = 0;

    public int $peerAck = 0;

    public bool $remoteClosed = false;

    public int $state = self::STATE_EMPTY;

    public int $lastSendTime = 0;

    public int $lastRecvTime = 0;

    public int $lastUpdateTime = 0;

    /**
     * @var array<int, PackageEncoder>
     */
    public array $chunksQueue = [];

    public function __construct()
    {
    }

    public function connect(string $address, int $port)
    {
        $this->state          = static::STATE_CONNECTING;
        $this->clientAddress  = $address;
        $this->clientPort     = $port;
        $this->lastSendTime   = time();
        $this->lastRecvTime   = time();
        $this->lastUpdateTime = time();

        $this->sendControlMessage(Network::CTRLMSG_CONNECT);
    }

    public function completeConnection(DecodedPacket $packet)
    {
        foreach ($packet->getChunks() as $chunk) {
            if ($chunk->getMessage() === Protocol::INFO) {
                $version = $chunk->extractString();

                echo $version.PHP_EOL;
            }
        }
    }

    public function feedConnection(DecodedPacket $packet)
    {
    }

    public function addChunk(PackageEncoder $chunk): static
    {
        $this->chunksQueue[] = $chunk;

        return $this;
    }

    public function sendChunks(): bool
    {
        $encoder = new PackageEncoder(0, $this->ack, $this->chunksQueue);

        return $encoder->send($this->clientAddress, $this->clientPort);
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        $encoder = PackageEncoder::makeControlMessage($message, $extra);

        return $encoder->send($this->clientAddress, $this->clientPort);
    }
}
