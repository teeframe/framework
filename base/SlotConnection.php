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
    const STATE_READY      = 3;
    const STATE_INGAME     = 4;
    const STATE_ERROR      = 5;

    public string $clientAddress;

    public int $clientPort;

    public int $sequence;

    public int $ack;

    public int $peerAck;

    public bool $remoteClosed;

    public int $state;

    public int $lastSendTime;

    public int $lastRecvTime;

    public int $lastUpdateTime;

    /**
     * @var array<int, PackageChunkEncoder>
     */
    public array $chunksQueue = [];

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->state          = static::STATE_EMPTY;
        $this->clientAddress  = '';
        $this->clientPort     = 0;
        $this->sequence       = 0;
        $this->ack            = 0;
        $this->peerAck        = 0;
        $this->remoteClosed   = false;
        $this->lastSendTime   = 0;
        $this->lastRecvTime   = 0;
        $this->lastUpdateTime = 0;
        $this->chunksQueue    = [];
    }

    public function startConnection(string $address, int $port): void
    {
        $this->state          = static::STATE_CONNECTING;
        $this->clientAddress  = $address;
        $this->clientPort     = $port;
        $this->lastSendTime   = time();
        $this->lastRecvTime   = time();
        $this->lastUpdateTime = time();

        $this->sendControlMessage(Network::CTRLMSG_CONNECTACCEPT);
        Instance::$console->info('got connection, sending accept');
    }

    public function completeConnection(DecodedPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            var_dump($chunk->getMessage());

            // Step 1
            if ($chunk->getMessage() === Protocol::INFO) {
                $version = $chunk->extractString();

                if ($version !== '0.6 626fce9a778df4d4') {
                    $this->sendControlMessage(Network::CTRLMSG_CLOSE, 'Wrong client version');
                    $this->reset();

                    return false;
                }

                $this->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::MAP_CHANGE)
                        ->addString('dm1')
                        ->addInt(-233464210)
                        ->addInt(5805)
                )->sendChunks();
            }

            // Step 2.1
            if ($chunk->getMessage() === Protocol::REQUEST_MAP_DATA) {
                $this->state = static::STATE_LOADING; // TODO: Implement map loading

                $this->sendControlMessage(Network::CTRLMSG_CLOSE, 'Server cannot send map data yet');
                $this->reset();

                return false;
            }

            // Step 2.2
            if ($chunk->getMessage() === Protocol::READY) {
                Instance::$console->info('player is ready. ClientID=X, addr='.$this->clientAddress.':'.$this->clientPort);

                $this->state = static::STATE_READY;

                // TODO: Add CGameContext::SendVoteSet(int ClientID)
                // (Send if there is a vote running)
                $this->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::SV_MOTD)
                        ->addString('Welcome to the server!')
                )->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::CON_READY)
                )->sendChunks();
            }

            // Step 3
            if ($chunk->getMessage() === Protocol::CL_START_INFO) {
                Instance::$console->info('player sent start info. ClientID=X, addr='.$this->clientAddress.':'.$this->clientPort);

                // TODO: Add the code from (MsgID == NETMSGTYPE_CL_STARTINFO)
                $this->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::SV_VOTECLEAROPTIONS)
                )->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::SV_TUNEPARAMS)
                        ->addInt(1000)
                        ->addInt(200)
                        ->addInt(50)
                        ->addInt(1320)
                        ->addInt(1200)
                        ->addInt(500)
                        ->addInt(150)
                        ->addInt(95)
                        ->addInt(38000)
                        ->addInt(8000)
                        ->addInt(300)
                        ->addInt(1500)
                        ->addInt(50)
                        ->addInt(55000)
                        ->addInt(200000)
                        ->addInt(140)
                        ->addInt(125)
                        ->addInt(200000)
                        ->addInt(140)
                        ->addInt(125)
                        ->addInt(220000)
                        ->addInt(200)
                        ->addInt(125)
                        ->addInt(275000)
                        ->addInt(80)
                        ->addInt(20)
                        ->addInt(700)
                        ->addInt(100000)
                        ->addInt(200)
                        ->addInt(80000)
                        ->addInt(15000)
                        ->addInt(100)
                        ->addInt(0)
                        ->addInt(500)
                        ->addInt(100)
                        ->addInt(10)
                )->addChunk(
                    PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::SV_READYTOENTER)
                )->sendChunks();
            }

            // Step 4
            if ($chunk->getMessage() === Protocol::ENTERGAME) {
                Instance::$console->info('player has entered the game. ClientID=X, addr='.$this->clientAddress.':'.$this->clientPort);

                $this->state = static::STATE_INGAME;
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
        if (in_array($this->state, [static::STATE_CONNECTING, static::STATE_READY])) {
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

        // EQUIVALENT - handle sequence stuff
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

        $this->chunksQueue = [];

        return $encoder->send($this->clientAddress, $this->clientPort);
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        $encoder = PackageEncoder::makeControlMessage($message, $extra);

        return $encoder->send($this->clientAddress, $this->clientPort);
    }
}
