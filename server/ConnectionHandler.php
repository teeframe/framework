<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
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
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\Sockets\AbstractSocket;

class ConnectionHandler
{
    /**
     * @var ConnectionSlot[]
     */
    protected array $slots = [];

    public function __construct(protected TickHandler $tickHandler) {}

    public function startNew(AbstractSocket $socket, AbstractWorld $world): ConnectionSlot|false
    {
        $this->removeClosedConnections();

        if ($this->isWorldFull($world)) {
            return false;
        }

        $this->slots[] = $slot = new ConnectionSlot($socket, $world, $this->tickHandler);

        return $slot;
    }

    /**
     * @param  array{address: string, port: int}  $clientInfo
     */
    public function findByEndpoint(array $clientInfo, AbstractSocket $socket): ConnectionSlot|false
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_CLOSED
                || $connection->socket() !== $socket
                || $connection->destinationAddress !== $clientInfo['address']
                || $connection->destinationPort !== $clientInfo['port']
            ) {
                continue;
            }

            return $connection;
        }

        return false;
    }

    /** Find an in-game connection by its world-local tee index. */
    public function findByTee(AbstractWorld $world, int $teeIndex): ?ConnectionSlot
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_INGAME
                && $connection->world() === $world
                && $connection->playerTee()->teeIndex === $teeIndex
            ) {
                return $connection;
            }
        }

        return null;
    }

    public function isWorldFull(AbstractWorld $world): bool
    {
        $connections = array_filter(
            $this->slots,
            fn (ConnectionSlot $slot): bool => $slot->world() === $world && $slot->state !== ConnectionSlot::STATE_CLOSED,
        );

        return count($connections) >= $world->getSlotsLimit();
    }

    /**
     * @return ConnectionSlot[]
     */
    public function getConnections(): array
    {
        return $this->slots;
    }

    public function update(): void
    {
        foreach ($this->slots as $connection) {
            if ($connection->state === ConnectionSlot::STATE_WAITING_INIT || $connection->state === ConnectionSlot::STATE_CLOSED) {
                continue;
            }

            if (($reason = $connection->update()) !== null) {
                $connection->closeConnection($reason);
            }
        }

        $this->removeClosedConnections();
    }

    public function startConnectionHandshake(ConnectionSlot $connection, string $address, int $port): void
    {
        $connection->init($address, $port);

        $connection->state = ConnectionSlot::STATE_CONNECTING;

        $connection->sendControlMessage(NetworkMessages::CONTROL_CONNECT_ACCEPT);
        $connection->consoleInfo('network', 'got connection, sending accept');
    }

    public function handleConnectionHandshake(ConnectionSlot $connection, AbstractPacket $packet, string $password): bool
    {
        if (! $connection->feedConnection($packet) || ! ($packet instanceof DefaultPacket)) {
            return false;
        }

        foreach ($connection->getReceivedChunks() as $chunk) {
            if ($connection->handleRconChunk($chunk)) {
                if ($connection->state === ConnectionSlot::STATE_CLOSED) {
                    return false;
                }

                continue;
            }

            // Step 1
            if ($chunk instanceof InfoChunk && $connection->state === ConnectionSlot::STATE_CONNECTING) {
                $connection->consoleDebug('network', 'player sent info');

                if (! $this->handleInfoChunk($connection, $chunk, $password)) {
                    return false;
                }
            }

            // Step 2.1
            if ($chunk instanceof RequestMapDataChunk && $connection->state === ConnectionSlot::STATE_LOADING) {
                $connection->consoleDebug('network', 'player requested map data');

                if (! $this->handleRequestMapDataChunk($connection, $chunk)) {
                    return false;
                }
            }

            // Step 2.2
            if ($chunk instanceof ReadyChunk && $connection->state === ConnectionSlot::STATE_LOADING) {
                $connection->consoleDebug('network', 'player is ready');

                $this->handleReadyChunk($connection, $chunk);
            }

            // Step 3
            if ($chunk instanceof ClStartInfoChunk && $connection->state === ConnectionSlot::STATE_READY && ! $connection->readyToEnter) {
                $connection->consoleDebug('network', 'player sent start info');

                $this->handleClStartInfoChunk($connection, $chunk);
            }

            // Step 4
            if ($chunk instanceof EnterGameChunk && $connection->state === ConnectionSlot::STATE_READY && $connection->readyToEnter) {
                $this->handleEnterGameChunk($connection, $chunk);

                $connection->consoleInfo('network', 'player has entered the game');
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

        $connection->state = ConnectionSlot::STATE_LOADING;

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

        if ($offset + $chunkSize >= $mapSize) {
            $chunkSize = $mapSize - $offset;
            $last      = 1;
        }

        $data = NetworkBase::unpackBuffer(substr($mapData, $offset, $chunkSize));

        $connection->chunks()->add(
            new MapDataChunk($last, $mapCrc, $chunk->chunk, $chunkSize, $data)
        )->send();

        return true;
    }

    protected function handleReadyChunk(ConnectionSlot $connection, ReadyChunk $chunk): void
    {
        $connection->state = ConnectionSlot::STATE_READY;

        if ($runningVote = $connection->world()->getVoteController()->getRunningVote($connection->world())) {
            $connection->chunks()->add($runningVote);
            $status = $connection->world()->getVoteController()->getRunningVoteStatus($connection->world());

            if ($status !== null) {
                $connection->chunks()->add($status);
            }
        }

        $connection->chunks()->add(
            new SvMotdChunk(str_replace('\\n', "\n", $connection->world()->getConfig()->getString('sv_world_motd')))
        )->add(
            new ConReadyChunk
        )->send();
    }

    protected function handleClStartInfoChunk(ConnectionSlot $connection, ClStartInfoChunk $chunk): void
    {
        $connection->playerTee()->setInfo(
            name: $chunk->name,
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

        $connection->readyToEnter = true;

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
        $connection->queueWorldInfoUpdate();
    }

    protected function removeClosedConnections(): void
    {
        foreach ($this->slots as $i => $connection) {
            if ($connection->state === ConnectionSlot::STATE_CLOSED) {
                unset($this->slots[$i]);
            }
        }

        $this->slots = array_values($this->slots);
    }
}
