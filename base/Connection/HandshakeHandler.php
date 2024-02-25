<?php

namespace Base\Connection;

use Network\Chunks\AbstractChunk;
use Network\Chunks\Game\ClStartInfoChunk;
use Network\Chunks\Game\SvMotdChunk;
use Network\Chunks\Game\SvReadyToEnterChunk;
use Network\Chunks\Game\SvTuneParamsChunk;
use Network\Chunks\Game\SvVoteClearOptionsChunk;
use Network\Chunks\System\ConReadyChunk;
use Network\Chunks\System\EnterGameChunk;
use Network\Chunks\System\InfoChunk;
use Network\Chunks\System\MapChangeChunk;
use Network\Chunks\System\ReadyChunk;
use Network\Chunks\System\RequestMapDataChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\Packets\DefaultPacket;

class HandshakeHandler
{
    public function __construct(
        protected ConnectionSlot $connection
    ) {
    }

    public function needsHandshake(): bool
    {
        return in_array($this->connection->state, [ConnectionSlot::STATE_CONNECTING, ConnectionSlot::STATE_LOADING, ConnectionSlot::STATE_READY]);
    }

    public function startHandshake(string $address, int $port): void
    {
        $this->connection->state          = ConnectionSlot::STATE_CONNECTING;
        $this->connection->clientAddress  = $address;
        $this->connection->clientPort     = $port;
        $this->connection->lastSendTime   = time();
        $this->connection->lastRecvTime   = time();

        $this->connection->sendControlMessage(Network::CTRLMSG_CONNECTACCEPT);
        $this->connection->consoleInfo('got connection, sending accept');
    }

    public function handleHandshake(DefaultPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            // Step 1
            if ($chunk instanceof InfoChunk) {
                $this->connection->consoleInfo('player sent info');

                if (! $this->handleInfoChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.1
            if ($chunk instanceof RequestMapDataChunk) {
                $this->connection->consoleInfo('player requested map data');

                if (! $this->handleRequestMapDataChunk($chunk)) {
                    return false;
                }
            }

            // Step 2.2
            if ($chunk instanceof ReadyChunk) {
                $this->connection->consoleInfo('player is ready');

                $this->handleReadyChunk($chunk);
            }

            // Step 3
            if ($chunk instanceof ClStartInfoChunk) {
                $this->connection->consoleInfo('player sent start info');

                $this->handleClStartInfoChunk($chunk);
            }

            // Step 4
            if ($chunk instanceof EnterGameChunk) {
                $this->connection->consoleInfo('player has entered the game');

                $this->handleEnterGameChunk($chunk);
            }

            // After step 4, the connection is completed. However,
            // the client will wait two snapshots before moving the player
            // from loading screen to the game.
        }

        return true;
    }

    protected function handleInfoChunk(InfoChunk $chunk): bool
    {
        if ($chunk->version !== '0.6 626fce9a778df4d4') {
            $this->connection->closeConnection('Wrong client version');

            return false;
        }

        // TODO: Implement password system

        $this->connection->chunks()->add(
            new MapChangeChunk('dm1', -233464210, 5805)
        )->send();

        return true;
    }

    protected function handleRequestMapDataChunk(RequestMapDataChunk $chunk): bool
    {
        $this->connection->state = ConnectionSlot::STATE_LOADING;

        // TODO: Implement map loading

        $this->connection->closeConnection('Server cannot send map data yet');

        return false;
    }

    protected function handleReadyChunk(ReadyChunk $chunk): void
    {
        $this->connection->state = ConnectionSlot::STATE_READY;

        // TODO: Add CGameContext::SendVoteSet(int ClientID), (To send if there is a vote running)

        $this->connection->chunks()->add(
            new SvMotdChunk('Welcome to the server!')
        )->add(
            new ConReadyChunk()
        )->send();
    }

    protected function handleClStartInfoChunk(ClStartInfoChunk $chunk): void
    {
        // TODO: Add the code from (MsgID == NETMSGTYPE_CL_STARTINFO)

        $this->connection->chunks()->add(
            new SvVoteClearOptionsChunk(),
        )->add(
            new SvTuneParamsChunk(
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
        )->add(
            new SvReadyToEnterChunk()
        )->send();
    }

    protected function handleEnterGameChunk(EnterGameChunk $chunk): void
    {
        $this->connection->state = ConnectionSlot::STATE_INGAME;

        // TODO: Add the code from GameServer()->OnClientEnter(ClientID)
    }
}