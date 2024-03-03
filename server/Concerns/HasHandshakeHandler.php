<?php

namespace TeeFrame\Server\Concerns;

use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\Game\SvReadyToEnterChunk;
use TeeFrame\Network\Chunks\Game\SvTuneParamsChunk;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;
use TeeFrame\Network\Chunks\System\ConReadyChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\System\RequestMapDataChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\ConnectionSlot;

trait HasHandshakeHandler
{
    public function startConnectionHandshake(ConnectionSlot $connection, string $address, int $port): void
    {
        $connection->init($address, $port);

        $connection->state = ConnectionSlot::STATE_CONNECTING;

        $connection->sendControlMessage(NetworkMessages::CONTROL_CONNECT_ACCEPT);
        $connection->consoleInfo('got connection, sending accept');
    }

    public function handleConnectionHandshake(ConnectionSlot $connection, DefaultPacket $packet): bool
    {
        foreach ($packet->getChunks() as $chunk) {
            // Step 1
            if ($chunk instanceof InfoChunk) {
                $connection->consoleInfo('player sent info');

                if (! $this->handleInfoChunk($connection, $chunk)) {
                    return false;
                }
            }

            // Step 2.1
            if ($chunk instanceof RequestMapDataChunk) {
                $connection->consoleInfo('player requested map data');

                if (! $this->handleRequestMapDataChunk($connection, $chunk)) {
                    return false;
                }
            }

            // Step 2.2
            if ($chunk instanceof ReadyChunk) {
                $connection->consoleInfo('player is ready');

                $this->handleReadyChunk($connection, $chunk);
            }

            // Step 3
            if ($chunk instanceof ClStartInfoChunk) {
                $connection->consoleInfo('player sent start info');

                $this->handleClStartInfoChunk($connection, $chunk);
            }

            // Step 4
            if ($chunk instanceof EnterGameChunk) {
                $connection->consoleInfo('player has entered the game');

                $this->handleEnterGameChunk($connection, $chunk);
            }

            // After step 4, the connection is completed. However,
            // the client will wait two snapshots before moving the player
            // from loading screen to the game.
        }

        return true;
    }

    protected function handleInfoChunk(ConnectionSlot $connection, InfoChunk $chunk): bool
    {
        if ($chunk->version !== '0.6 626fce9a778df4d4') {
            $connection->closeConnection('Wrong client version');

            return false;
        }

        // TODO: Implement password system

        $connection->chunks()->add(
            new MapChangeChunk('dm1', -233464210, 5805)
        )->send();

        return true;
    }

    protected function handleRequestMapDataChunk(ConnectionSlot $connection, RequestMapDataChunk $chunk): bool
    {
        $connection->state = ConnectionSlot::STATE_LOADING;

        // TODO: Implement map loading

        $connection->closeConnection('Server cannot send map data yet');

        return false;
    }

    protected function handleReadyChunk(ConnectionSlot $connection, ReadyChunk $chunk): void
    {
        $connection->state = ConnectionSlot::STATE_READY;

        // TODO: Add CNetMsg_Sv_VoteOptionListAdd OptionMsg (To send votes list)

        // TODO: Add CGameContext::SendVoteSet(int ClientID), (To send if there is a vote running)

        $connection->chunks()->add(
            new SvMotdChunk('Welcome to the server!')
        )->add(
            new ConReadyChunk
        )->send();
    }

    protected function handleClStartInfoChunk(ConnectionSlot $connection, ClStartInfoChunk $chunk): void
    {
        $connection->player()->name           = $chunk->name;
        $connection->player()->clan           = $chunk->clan;
        $connection->player()->country        = $chunk->country;
        $connection->player()->skinName       = $chunk->skinName;
        $connection->player()->useCustomColor = $chunk->useCustomColor;
        $connection->player()->colorBody      = $chunk->colorBody;
        $connection->player()->colorFeet      = $chunk->colorFeet;

        $connection->chunks()->add(
            new SvVoteClearOptionsChunk,
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
            new SvReadyToEnterChunk
        )->send();
    }

    protected function handleEnterGameChunk(ConnectionSlot $connection, EnterGameChunk $chunk): void
    {
        $connection->state = ConnectionSlot::STATE_INGAME;

        // TODO: Add the code from GameServer()->OnClientEnter(ClientID)
    }
}
