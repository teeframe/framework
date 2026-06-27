<?php

namespace TeeFrame\Server\Concerns;

use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\Game\SvReadyToEnterChunk;
use TeeFrame\Network\Chunks\System\ConReadyChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\MapDataChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\System\RequestMapDataChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\AbstractPacket;
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

    public function handleConnectionHandshake(ConnectionSlot $connection, AbstractPacket $packet, string $password): bool
    {
        if (! ($packet instanceof DefaultPacket)) {
            return false;
        }

        foreach ($packet->getChunks() as $chunk) {
            // Step 1
            if ($chunk instanceof InfoChunk) {
                $connection->consoleInfo('player sent info');

                if (! $this->handleInfoChunk($connection, $chunk, $password)) {
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

    protected function handleInfoChunk(ConnectionSlot $connection, InfoChunk $chunk, string $password): bool
    {
        if ($chunk->version !== '0.6 626fce9a778df4d4') {
            $connection->closeConnection('Wrong client version');

            return false;
        }

        if ($chunk->password !== $password) {
            $connection->closeConnection('Wrong password');

            return false;
        }

        [$mapName, $mapCrc, $mapSize] = $connection->world()->getMapInfo();

        $connection->chunks()->add(
            new MapChangeChunk($mapName, $mapCrc, $mapSize)
        )->send();

        return true;
    }

    protected function handleRequestMapDataChunk(ConnectionSlot $connection, RequestMapDataChunk $chunk): bool
    {
        $connection->state = ConnectionSlot::STATE_LOADING;

        $map       = $connection->world()->getMap();
        $mapData   = $map->getRawData();
        $mapSize   = $map->getSize();
        $mapCrc    = $map->getCrc();
        $chunkSize = 1024 - 128; // 896 bytes per chunk
        $offset    = $chunk->chunk * $chunkSize;
        $last      = 0;

        // Drop faulty map data requests
        if ($chunk->chunk < 0 || $offset > $mapSize) {
            return true;
        }

        if ($offset + $chunkSize > $mapSize) {
            $chunkSize = $mapSize - $offset;
            $last      = 1;
        }

        $data = array_slice($mapData, $offset, $chunkSize);

        $connection->chunks()->add(
            new MapDataChunk($last, $mapCrc, $chunk->chunk, $chunkSize, $data)
        )->send();

        return true;
    }

    protected function handleReadyChunk(ConnectionSlot $connection, ReadyChunk $chunk): void
    {
        $connection->state = ConnectionSlot::STATE_READY;

        if ($runningVote = $connection->world()->getVoteController()->getRunningVote($connection->playerTee())) {
            $connection->chunks()->add($runningVote);
        }

        $connection->chunks()->add(
            new SvMotdChunk($connection->world()->getMotd($connection->playerTee()))
        )->add(
            new ConReadyChunk
        )->send();
    }

    protected function handleClStartInfoChunk(ConnectionSlot $connection, ClStartInfoChunk $chunk): void
    {
        $world = $connection->world();

        $world->getServer()->setClientName($world, $connection->playerTee(), $chunk->name);

        $connection->playerTee()->setInfo(
            name: $connection->playerTee()->name,
            clan: $chunk->clan,
            country: $chunk->country,
            skinName: $chunk->skinName,
            useCustomColor: $chunk->useCustomColor,
            colorBody: $chunk->colorBody,
            colorFeet: $chunk->colorFeet
        );
        
        $voteChunks = $connection->world()->getVoteController()->getInitialVoteChunks($connection->playerTee());
        foreach ($voteChunks as $voteChunk) {
            $connection->chunks()->add($voteChunk);
        }

        $connection->chunks()->add(
            $connection->world()->getTuneController()->getTuneParamsChunk()
        )->add(
            new SvReadyToEnterChunk
        )->send();
    }

    protected function handleEnterGameChunk(ConnectionSlot $connection, EnterGameChunk $chunk): void
    {
        $connection->state = ConnectionSlot::STATE_INGAME;

        $connection->world()->addTee($connection->playerTee());
    }
}
