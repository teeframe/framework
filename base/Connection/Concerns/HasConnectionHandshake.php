<?php

namespace Base\Connection\Concerns;

use Base\Connection\ConnectionSlot;
use Network\Decoder\DecodedPacket;
use Network\Decoder\DecodedPacketChunk;
use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

trait HasConnectionHandshake
{
    public function isConnectionOnHandshake(): bool
    {
        return in_array($this->state, [ConnectionSlot::STATE_CONNECTING, ConnectionSlot::STATE_LOADING, ConnectionSlot::STATE_READY]);
    }

    public function startHandshakeConnection(string $address, int $port): void
    {
        $this->state          = ConnectionSlot::STATE_CONNECTING;
        $this->clientAddress  = $address;
        $this->clientPort     = $port;
        $this->lastSendTime   = time();
        $this->lastRecvTime   = time();
        $this->lastUpdateTime = time();

        $this->sendControlMessage(Network::CTRLMSG_CONNECTACCEPT);
        $this->consoleInfo('got connection, sending accept');
    }

    public function handleHandshakeConnection(DecodedPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            // Step 1
            if ($chunk->getMessage() === Protocol::INFO) {
                if (! $this->handleInfoChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.1
            if ($chunk->getMessage() === Protocol::REQUEST_MAP_DATA) {
                if (! $this->handleRequestMapDataChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.2
            if ($chunk->getMessage() === Protocol::READY) {
                $this->consoleInfo('player is ready.');

                $this->handleReadyChunk($chunk);
            }

            // Step 3
            if ($chunk->getMessage() === Protocol::CL_START_INFO) {
                $this->consoleInfo('player sent start info.');

                $this->handleClStartInfoChunk($chunk);
            }

            // Step 4
            if ($chunk->getMessage() === Protocol::ENTERGAME) {
                $this->consoleInfo('player has entered the game.');

                $this->handleEnterGameChunk($chunk);
            }

            // NOTE: After step 4, the connection is completed. However,
            // the client will wait two snapshots before moving the player
            // from loading screen to the game.
        }

        return true;
    }

    protected function handleInfoChunk(DecodedPacketChunk $chunk): bool
    {
        $version = $chunk->extractString();

        if ($version !== '0.6 626fce9a778df4d4') {
            $this->sendControlMessage(Network::CTRLMSG_CLOSE, 'Wrong client version');
            $this->reset();

            return false;
        }

        // TODO: Implement password system

        $this->addChunk(
            PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::MAP_CHANGE)
                ->addString('dm1')
                ->addInt(-233464210)
                ->addInt(5805)
        )->sendChunks();

        return true;
    }

    protected function handleRequestMapDataChunk(DecodedPacketChunk $chunk): bool
    {
        $this->state = ConnectionSlot::STATE_LOADING;

        // TODO: Implement map loading

        $this->sendControlMessage(Network::CTRLMSG_CLOSE, 'Server cannot send map data yet');
        $this->reset();

        return false;
    }

    protected function handleReadyChunk(DecodedPacketChunk $chunk): void
    {
        $this->state = ConnectionSlot::STATE_READY;

        // TODO: Add CGameContext::SendVoteSet(int ClientID), (To send if there is a vote running)

        $this->addChunk(
            PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::SV_MOTD)
                ->addString('Welcome to the server!')
        )->addChunk(
            PackageChunkEncoder::make(Network::CHUNKFLAG_VITAL, Protocol::CON_READY)
        )->sendChunks();
    }

    protected function handleClStartInfoChunk(DecodedPacketChunk $chunk): void
    {
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

    protected function handleEnterGameChunk(DecodedPacketChunk $chunk): void
    {
        $this->state = ConnectionSlot::STATE_INGAME;

        // TODO: Add the code from GameServer()->OnClientEnter(ClientID)
    }
}
