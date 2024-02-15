<?php

namespace Base\Connection\Concerns;

use Base\Connection\ConnectionSlot;
use Network\Decoder\DecodedPacket;
use Network\Decoder\DecodedPacketChunk;
use Network\Encoder\Chunks\Game\SvMotdChunk;
use Network\Encoder\Chunks\Game\SvReadyToEnterChunk;
use Network\Encoder\Chunks\Game\SvTuneParamsChunk;
use Network\Encoder\Chunks\Game\SvVoteClearOptionsChunk;
use Network\Encoder\Chunks\System\ConReadyChunk;
use Network\Encoder\Chunks\System\MapChangeChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;

trait HasConnectionHandshake
{
    public function isConnectionOnHandshake(): bool
    {
        return in_array($this->state, [ConnectionSlot::STATE_CONNECTING, ConnectionSlot::STATE_LOADING, ConnectionSlot::STATE_READY]);
    }

    public function startConnectionHandshake(string $address, int $port): void
    {
        // TODO: Implement ban system

        $this->state          = ConnectionSlot::STATE_CONNECTING;
        $this->clientAddress  = $address;
        $this->clientPort     = $port;
        $this->lastSendTime   = time();
        $this->lastRecvTime   = time();
        $this->lastUpdateTime = time();

        $this->sendControlMessage(Network::CTRLMSG_CONNECTACCEPT);
        $this->consoleInfo('got connection, sending accept');
    }

    public function handleConnectionHandshake(DecodedPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            // Step 1
            if ($chunk->getMessage() === Protocol::INFO) {
                $this->consoleInfo('player sent info');

                if (! $this->handleInfoChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.1
            if ($chunk->getMessage() === Protocol::REQUEST_MAP_DATA) {
                $this->consoleInfo('player requested map data');

                if (! $this->handleRequestMapDataChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.2
            if ($chunk->getMessage() === Protocol::READY) {
                $this->consoleInfo('player is ready');

                $this->handleReadyChunk($chunk);
            }

            // Step 3
            if ($chunk->getMessage() === Protocol::CL_START_INFO) {
                $this->consoleInfo('player sent start info');

                $this->handleClStartInfoChunk($chunk);
            }

            // Step 4
            if ($chunk->getMessage() === Protocol::ENTERGAME) {
                $this->consoleInfo('player has entered the game');

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
            $this->closeConnection('Wrong client version');

            return false;
        }

        // TODO: Implement password system

        $this->addChunk(
            MapChangeChunk::make('dm1', -233464210, 5805)
        )->sendChunks();

        return true;
    }

    protected function handleRequestMapDataChunk(DecodedPacketChunk $chunk): bool
    {
        $this->state = ConnectionSlot::STATE_LOADING;

        // TODO: Implement map loading

        $this->closeConnection('Server cannot send map data yet');

        return false;
    }

    protected function handleReadyChunk(DecodedPacketChunk $chunk): void
    {
        $this->state = ConnectionSlot::STATE_READY;

        // TODO: Add CGameContext::SendVoteSet(int ClientID), (To send if there is a vote running)

        $this->addChunk(
            SvMotdChunk::make('Welcome to the server!')
        )->addChunk(
            ConReadyChunk::make()
        )->sendChunks();
    }

    protected function handleClStartInfoChunk(DecodedPacketChunk $chunk): void
    {
        // TODO: Add the code from (MsgID == NETMSGTYPE_CL_STARTINFO)

        $this->addChunk(
            SvVoteClearOptionsChunk::make(),
        )->addChunk(
            SvTuneParamsChunk::make(
                groundControlSpeed: 1000,
                groundControlAccel: 200,
                groundFriction: 50,
                groundJumpImpulse: 1320,
                airJumpImpulse: 1200,
                airControlSpeed: 500,
                airControlAccel: 150,
                airFriction: 95,
                hookLength: 38000,
                hookFireSpeed: 8000,
                hookDragAccel: 300,
                hookDragSpeed: 1500,
                gravity: 50,
                velrampStart: 55000,
                velrampRange: 200000,
                velrampCurvature: 140,
                gunCurvature: 125,
                gunSpeed: 220000,
                gunLifetime: 200,
                shotgunCurvature: 125,
                shotgunSpeed: 275000,
                shotgunSpeeddiff: 80,
                shotgunLifetime: 20,
                grenadeCurvature: 700,
                grenadeSpeed: 100000,
                grenadeLifetime: 200,
                laserReach: 80000,
                laserBounceDelay: 15000,
                laserBounceNum: 100,
                laserBounceCost: 0,
                laserDamage: 500,
                playerCollision: 100,
                playerHooking: 100
            )
        )->addChunk(
            SvReadyToEnterChunk::make()
        )->sendChunks();
    }

    protected function handleEnterGameChunk(DecodedPacketChunk $chunk): void
    {
        $this->state = ConnectionSlot::STATE_INGAME;

        // TODO: Add the code from GameServer()->OnClientEnter(ClientID)
    }
}
