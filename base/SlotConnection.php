<?php

namespace Base;

use Network\Decoder\DecodedPacket;
use Network\Encoder\PackageChunkEncoder;
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
     * @var array<int, PackageChunkEncoder>
     */
    public array $chunksQueue = [];

    public function __construct()
    {
    }

    public function startConnection(string $address, int $port): void
    {
        $this->state          = static::STATE_CONNECTING;
        $this->clientAddress  = $address;
        $this->clientPort     = $port;
        $this->sequence       = 0;
        $this->ack            = 0;
        $this->peerAck        = 0;
        $this->remoteClosed   = false;
        $this->lastSendTime   = time();
        $this->lastRecvTime   = time();
        $this->lastUpdateTime = time();

        $this->sendControlMessage(Network::CTRLMSG_CONNECTACCEPT);
        Instance::$console->info('got connection, sending accept');
    }

    public function completeConnection(DecodedPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            if ($chunk->getMessage() === Protocol::INFO) {
                $version = $chunk->extractString();

                if ($version !== '0.6 626fce9a778df4d4') {
                    $this->sendControlMessage(Network::CTRLMSG_CLOSE, 'Wrong client version');
                    $this->state = static::STATE_EMPTY;

                    return false;
                }

                $this->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::MAP_CHANGE)
                        ->addString('dm1')
                        ->addInt(-233464210)
                        ->addInt(5805)
                )->sendChunks();
            }
        }

        return true;
    }

    public function feedConnection(DecodedPacket $packet): bool
    {
        if ($this->sequence >= $this->peerAck) {
            if ($packet->getAck() < $this->peerAck || $packet->getAck() > $this->sequence) {
                Instance::$console->error('Invalid ack');

                return false;
            }
        } else {
            if ($packet->getAck() < $this->peerAck && $packet->getAck() > $this->sequence) {
                Instance::$console->error('Invalid ack');

                return false;
            }
        }

        $this->updateConnectionAck($packet);

        // Handle connecting connection
        if ($this->state === static::STATE_CONNECTING) {
            return $this->completeConnection($packet);
        }

        // Handle online connection
        if ($packet->getFlags() & Network::PACKETFLAG_RESEND) {
            return $this->handleResendPacket($packet);
        }
        if ($packet->getFlags() & Network::PACKETFLAG_CONTROL) {
            return $this->handleControlMessagePacket($packet);
        }

        return $this->handleDefaultPacket($packet);
    }

    public function updateConnectionAck(DecodedPacket $packet): void
    {
        $this->peerAck = $packet->getAck();

        foreach ($packet->getChunks() as $chunk) {
            if (! ($chunk->getFlags() & Network::CHUNKFLAG_VITAL)) {
                continue;
            }

            if ($chunk->getSequence() === ($this->ack + 1)) {
                $this->ack++;
            } else {
                // TODO: Implement chunk resending
            }
        }
    }

    public function handleResendPacket(DecodedPacket $packet): bool
    {
        // TODO: Implement CNetConnection::Resend()

        return true;
    }

    public function handleControlMessagePacket(DecodedPacket $packet): bool
    {
        $message = $packet->getControlMessage();

        if ($message === Network::CTRLMSG_KEEPALIVE) {
            return true;
        }
        if ($message === Network::CTRLMSG_CLOSE) {
            $this->remoteClosed = true;
            $this->state        = static::STATE_EMPTY;

            Instance::$console->info('Closed reason='.$packet->getControlMessageExtra());

            return true;
        }

        return false;
    }

    public function handleDefaultPacket(DecodedPacket $packet): bool
    {
        // if ($this->state === NetConnState::ONLINE) {
        //     $this->lastRecvTime = time();

        //     Console::info('connected client');
        //     // AckChunks
        // }

        return true;
    }

    public function addChunk(PackageChunkEncoder $chunk): static
    {
        $this->chunksQueue[] = $chunk;

        if ($chunk->getFlags() & Network::CHUNKFLAG_VITAL) {
            $this->sequence++;

            $chunk->setSequence($this->sequence);
        }

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
