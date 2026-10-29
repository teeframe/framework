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
        protected int $slotsLimit,
        protected string $password = '',
        protected int $kickBanDuration = 600,
    )
    {
        $this->connectionHandler = new ConnectionHandler(slotsLimit: $slotsLimit, tickHandler: $tickHandler);
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
        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            if ($connection->world() === $world && $connection->playerTee()->teeIndex === $teeIndex) {
                // Insert chunk
                $connection->chunks()->add($chunk);

                // And send chunk instantly, don't wait for the queue
                $connection->chunks()->send();

                return;
            }
        }
    }

    public function trySetClientName(AbstractWorld $world, AbstractTee $tee, string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        if ($tee->name !== '' && $tee->name === $trimmed) {
            return true;
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
        foreach ($this->connectionHandler->getConnections() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            if ($connection->world() === $world && $connection->playerTee()->teeIndex === $teeIndex) {
                // Ban the address so they can't reconnect immediately
                $this->banList->ban($connection->destinationAddress, $this->kickBanDuration, $reason);

                $connection->closeConnection($reason);

                return;
            }
        }
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
            $socket->sendto(
                $clientInfo['address'],
                $clientInfo['port'],
                (new ControlMessage(NetworkMessages::CONTROL_CLOSE, 'The server is full'))->encodeToSend()
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
        $world = $connection && $connection->state !== ConnectionSlot::STATE_CLOSED
            ? $connection->world()
            : $this->selectWorldForNewConnection();
        $controller = $world->getGameController();

        $token = ord($data[strlen($header)]) | ($extraToken << 8);
        $maxClients = $this->connectionHandler->getSlotsLimit();
        $connections = array_values(array_filter(
            $this->connectionHandler->getConnections(),
            fn (ConnectionSlot $slot): bool => $slot->world() === $world
                && $slot->state >= ConnectionSlot::STATE_CONNECTING
                && $slot->state <= ConnectionSlot::STATE_INGAME,
        ));

        $numPlayers = count(array_filter(
            $connections,
            fn (ConnectionSlot $slot): bool => $slot->playerTee()->team !== GameConstants::TEAM_SPECTATORS,
        ));

        // All numbers in server-info replies are decimal strings, not packed integers.
        $fields = [
            $token,
            '0.6 TeeFrame',
            substr($this->name, 0, 256),
            substr($world->getMap()->getName(), 0, 32),
            $world->getMap()->getCrc(),
            $world->getMap()->getSize(),
            substr($controller->getGameType(), 0, 16),
            (int) ($this->password !== ''),
            $numPlayers,
            max($numPlayers, $maxClients - $controller->getSpectatorSlots()),
            count($connections),
            $maxClients,
            '', // Reserved server info.
        ];

        $payload = NetworkMessages::SERVERBROWSE_INFO_EXTENDED.$this->encodeServerInfoFields($fields);
        $packetNo = 0;
        foreach ($connections as $slot) {
            $tee = $slot->playerTee();
            $inGame = $slot->state === ConnectionSlot::STATE_INGAME;
            $clientFields = [
                $inGame ? substr($tee->name, 0, NetworkParams::MAX_NAME_LENGTH) : '(connecting)',
                $inGame ? substr($tee->clan, 0, NetworkParams::MAX_CLAN_LENGTH) : '',
                $tee->country,
                $tee->score,
                (int) ($tee->team !== GameConstants::TEAM_SPECTATORS),
                '', // Reserved client info.
            ];
            $clientPayload = $this->encodeServerInfoFields($clientFields);

            if (strlen($payload) + strlen($clientPayload) + NetworkParams::PACKET_HEADER_SIZE_CONNECTION_LESS > NetworkParams::MAXIMUM_PACKET_SIZE) {
                $socket->sendto($clientInfo['address'], $clientInfo['port'], (new ConnectionlessPacket($payload))->encodeToSend());
                $payload = NetworkMessages::SERVERBROWSE_INFO_EXTENDED_MORE
                    .$this->encodeServerInfoFields([$token, ++$packetNo, '']);
            }
            $payload .= $clientPayload;
        }

        $socket->sendto($clientInfo['address'], $clientInfo['port'], (new ConnectionlessPacket($payload))->encodeToSend());
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
