<?php

namespace TeeFrame\Server;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\Ban\BanList;
use TeeFrame\Server\Sockets\AbstractSocket;

abstract class AbstractServerInstance
{
    /**
     * @var AbstractWorld[]
     */
    protected array $worlds = [];

    /**
     * @var AbstractSocket[]
     */
    protected array $sockets = [];

    protected ConnectionHandler $connectionHandler;

    protected BanList $banList;

    public function __construct(
        protected TickHandler $tickHandler,
        protected string $name,
        protected string $password = '',
        protected int $kickBanDuration = 600,
    )
    {
        $this->connectionHandler = new ConnectionHandler(tickHandler: $tickHandler);
        $this->banList           = new BanList;
    }

    abstract protected function boot(): void;

    abstract protected function selectWorldForNewConnection(): AbstractWorld;

    public function start(): void
    {
        $this->boot();

        if (empty($this->worlds)) {
            throw new \RuntimeException('No worlds were booted');
        }

        if (empty($this->sockets)) {
            throw new \RuntimeException('No sockets were booted');
        }

        swoole_timer_tick(1000 / NetworkParams::TICKS_PER_SECOND, function (): void {
            $this->doTick();
        });

        foreach ($this->sockets as $socket) {
            $socket->on('packet', function (mixed $server, string $data, array $clientInfo) use ($socket): void {
                $packet = PacketDecoder::decodeFromRaw($data);
                if ($packet === false) {
                    return;
                }

                /** @var array{address: string, port: int} $clientInfo */
                $this->onPacket($packet, $clientInfo, $socket);
            });

            $socket->start();
        }
    }

    public function shutdown(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->shutdown();
        }
    }

    protected function doTick(): void
    {
        $this->tickHandler->startTick();

        foreach ($this->worlds as $world) {
            $world->doTick();
        }

        $this->doSnap();

        $this->tickHandler->next();

        if ($this->tickHandler->get() >= NetworkParams::MAXIMUM_TICK) {
            $this->shutdown();
        }

        // TODO: master server stuff
    }

    protected function doSnap(): void
    {
        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $connection->snaps()->sendItems(
                currentTick: $this->tickHandler->get(),
                rawItems: $connection->world()->doSnap($connection->playerTee()),
            );
        }

        foreach ($this->worlds as $world) {
            $world->clearEvents();
        }
    }

    public function sendToTee(AbstractWorld $world, int $teeIndex, AbstractChunk $chunk): void
    {
        $connection = $this->connectionHandler->findByTee($world, $teeIndex);
        $connection?->chunks()->add($chunk)->send();
    }

    public function changeWorld(AbstractTee $tee, AbstractWorld $world): bool
    {
        if (! in_array($world, $this->worlds, true)) {
            return false;
        }

        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME || $connection->playerTee() !== $tee) {
                continue;
            }

            if ($connection->world() === $world) {
                return true;
            }

            if ($this->connectionHandler->isWorldFull($world)) {
                return false;
            }

            $connection->changeWorld($world);

            return true;
        }

        return false;
    }

    public function reloadWorld(AbstractWorld $world): void
    {
        foreach ($this->connectionHandler->getConnections() as $connection) {
            // CONNECTING clients must still pass version/password validation.
            if ($connection->world() === $world
                && $connection->state >= ConnectionSlot::STATE_LOADING
                && $connection->state <= ConnectionSlot::STATE_INGAME
            ) {
                $connection->changeWorld($world);
            }
        }
    }

    public function trySetClientName(AbstractWorld $world, AbstractTee $tee, string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }


        foreach ($world->getTees() as $existingTee) {
            if ($existingTee === $tee) {
                continue;
            }

            if ($existingTee->name === $trimmed) {
                return false;
            }
        }

        $tee->name = $trimmed;

        return true;
    }

    public function setClientName(AbstractWorld $world, AbstractTee $tee, string $name): void
    {
        $cleanName = '';
        for ($i = 0, $len = strlen($name); $i < $len; $i++) {
            $cleanName .= $name[$i] < ' ' ? ' ' : $name[$i];
        }

        if ($this->trySetClientName($world, $tee, $cleanName)) {
            return;
        }

        for ($i = 1;; $i++) {
            $nameTry = "({$i}){$cleanName}";
            if ($this->trySetClientName($world, $tee, $nameTry)) {
                return;
            }
        }
    }

    public function kick(AbstractWorld $world, int $teeIndex, string $reason): void
    {
        $connection = $this->connectionHandler->findByTee($world, $teeIndex);
        if ($connection === null) {
            return;
        }

        // Ban the address so they can't reconnect immediately.
        $this->banList->ban($connection->destinationAddress, $this->kickBanDuration, $reason);
        $connection->closeConnection($reason);
    }

    /**
     * @param array{address: string, port: int} $clientInfo
     */
    protected function onPacket(AbstractPacket $packet, array $clientInfo, AbstractSocket $socket): void
    {
        if ($packet instanceof ConnectionlessPacket) {
            $this->handleServerInfoRequest($packet, $clientInfo, $socket);

            return;
        }

        if ($connectionSlot = $this->connectionHandler->tryToMatch($clientInfo)) {
            if ($connectionSlot->state !== ConnectionSlot::STATE_INGAME) {
                $this->connectionHandler->handleConnectionHandshake($connectionSlot, $packet, $this->password);

                return;
            }

            $connectionSlot->feedConnection($packet);
        }

        // Is not a connect control message...
        if (! ($packet instanceof ControlMessage)) {
            return;
        }

        if ($packet->getControlMessage() !== NetworkMessages::CONTROL_CONNECT) {
            return;
        }

        // Ban system: reject connections from banned addresses
        if ($this->banList->isBanned($clientInfo['address'])) {
            $ban = $this->banList->getBan($clientInfo['address']);

            if ($ban === null) {
                return; // Impossible scenario, TODO: Refactor this to avoid this null check
            }

            $minutesLeft = (int) ceil(($ban->expiry - time()) / 60);
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'You are banned for ' . $minutesLeft . ' more minute(s): ' . ($ban->reason ?? 'Kicked by vote')))->encodeToSend()
            );

            return;
        }

        // New connection...
        $slotConnection = $this->connectionHandler->startNew($socket, $this->selectWorldForNewConnection());
        if (! $slotConnection) {
            $reason = count($this->worlds) > 1
                ? 'The entry-level world is full'
                : 'The server is full';

            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, $reason))->encodeToSend()
            );

            return;
        }

        $this->connectionHandler->startConnectionHandshake($slotConnection, $clientInfo['address'], $clientInfo['port']);
    }

    /**
     * @param array{address: string, port: int} $clientInfo
     */
    protected function handleServerInfoRequest(ConnectionlessPacket $packet, array $clientInfo, AbstractSocket $socket): void
    {
        $data = $packet->getData();
        $header = NetworkMessages::SERVERBROWSE_GETINFO;
        $extraToken = $packet->getExtraToken();

        if ($extraToken === null || strlen($data) !== strlen($header) + 1 || ! str_starts_with($data, $header)) {
            return;
        }

        $connection = $this->connectionHandler->tryToMatch($clientInfo);
        $worlds = $connection && $connection->state !== ConnectionSlot::STATE_CLOSED
            ? [$connection->world()]
            : $this->worlds;

        if ($worlds === []) {
            return;
        }

        $token = ord($data[strlen($header)]) | ($extraToken << 8);

        foreach ($this->buildServerInfoPackets($worlds, $token) as $reply) {
            $socket->sendto($clientInfo['address'], $clientInfo['port'], $reply->encodeToSend());
        }

        // The client queries server info through a separate contact socket, so
        // the query does not match its gameplay connection. The aggregate reply
        // can overwrite its in-game Server tab with MW and the total capacity.
        // Queue a world-specific update on the gameplay socket to restore it,
        // deferred until a newer snapshot ACK to avoid a same-poll overwrite.
        //
        // The query contains no player/world identifier. The IP only filters
        // which connections to refresh; it never selects a world. Players
        // sharing an IP therefore all get a refresh, each for their own world.
        if (! $connection || $connection->state === ConnectionSlot::STATE_CLOSED) {
            foreach ($this->connectionHandler->getConnections() as $slot) {
                if ($slot->destinationAddress === $clientInfo['address'] && $slot->state !== ConnectionSlot::STATE_CLOSED) {
                    $slot->queueWorldInfoUpdate();
                }
            }
        }
    }

    public function sendWorldInfo(ConnectionSlot $connection): void
    {
        if ($connection->state !== ConnectionSlot::STATE_INGAME) {
            return;
        }

        foreach ($this->buildServerInfoPackets([$connection->world()], -1) as $packet) {
            $connection->sendPacket($packet);
        }
    }

    /**
     * @param  non-empty-array<AbstractWorld>  $worlds
     * @return list<ConnectionlessPacket>
     */
    protected function buildServerInfoPackets(array $worlds, int $token): array
    {
        $maxClients = 0;
        $maxPlayers = 0;

        foreach ($worlds as $world) {
            $slotsLimit = $world->getSlotsLimit();

            $maxClients += $slotsLimit;
            $maxPlayers += $slotsLimit - $world->getGameController()->getSpectatorSlots();
        }

        $connections = [];
        $numPlayers  = 0;

        foreach ($this->connectionHandler->getConnections() as $slot) {
            if (! in_array($slot->world(), $worlds, true)
                || $slot->state < ConnectionSlot::STATE_CONNECTING
                || $slot->state > ConnectionSlot::STATE_INGAME
            ) {
                continue;
            }

            $connections[] = $slot;

            if ($slot->playerTee()->team !== GameConstants::TEAM_SPECTATORS) {
                $numPlayers++;
            }
        }

        $world      = reset($worlds);
        $multiWorld = count($worlds) > 1;
        $packets    = [];
        $packetNo   = 0;

        // All numbers in server-info replies are decimal strings, not packed integers.
        $payload = NetworkMessages::SERVERBROWSE_INFO_EXTENDED.$this->encodeServerInfoFields([
            $token,
            '0.6 TeeFrame',
            substr($this->name, 0, 256),
            $multiWorld ? 'MultiWorld' : substr($world->getMap()->getName(), 0, 32),
            $multiWorld ? 0 : $world->getMap()->getCrc(),
            $multiWorld ? 0 : $world->getMap()->getSize(),
            $multiWorld ? 'MW' : substr($world->getGameController()->getGameType(), 0, 16),
            (int) ($this->password !== ''),
            $numPlayers,
            max($numPlayers, $maxPlayers),
            count($connections),
            $maxClients,
            '', // Reserved server info.
        ]);

        foreach ($connections as $slot) {
            $tee    = $slot->playerTee();
            $inGame = $slot->state === ConnectionSlot::STATE_INGAME;

            $clientPayload = $this->encodeServerInfoFields([
                $inGame ? substr($tee->name, 0, NetworkParams::MAX_NAME_LENGTH) : '(connecting)',
                $inGame ? substr($tee->clan, 0, NetworkParams::MAX_CLAN_LENGTH) : '',
                $tee->country,
                $tee->score,
                (int) ($tee->team !== GameConstants::TEAM_SPECTATORS),
                '', // Reserved client info.
            ]);

            if (strlen($payload) + strlen($clientPayload) + NetworkParams::PACKET_HEADER_SIZE_CONNECTION_LESS > NetworkParams::MAXIMUM_PACKET_SIZE) {
                $packets[] = new ConnectionlessPacket($payload);
                $payload   = NetworkMessages::SERVERBROWSE_INFO_EXTENDED_MORE
                    .$this->encodeServerInfoFields([$token, ++$packetNo, '']);
            }

            $payload .= $clientPayload;
        }

        $packets[] = new ConnectionlessPacket($payload);

        return $packets;
    }

    /** @param array<string|int> $fields */
    protected function encodeServerInfoFields(array $fields): string
    {
        return implode("\0", array_map(
            fn (string|int $field): string => explode("\0", (string) $field, 2)[0],
            $fields,
        ))."\0";
    }
}
