<?php

namespace Base\Server;

use Base\Console;
use Game\GameContext;
use Network\Decoder\DecodedPacket;
use Network\Encoder\PackageEncoder;
use Network\Enums\Network;
use Swoole\Server;

class ServerSocket extends Server
{
    use Concerns\HasConnectionSlots;

    protected GameContext $context;

    public function start(): bool
    {
        Console::info('Server is starting...');

        ServerInstance::$socket = $this;

        // TODO: Implement LoadMap(g_Config.m_SvMap)

        $this->context = new GameContext;

        // TODO: Implement GameServer()->OnInit()

        swoole_timer_tick(1000 / 50, function (): void {
            $this->context->doTick();
        });

        $this->initializeConnectionSlots();

        Console::info("Server started on {$this->host}:{$this->port}");

        return parent::start();
    }

    public function shutdown(): bool
    {
        Console::info('Server is shutting down...');

        $this->closeAllConnections('Server shutdown');

        Console::info('Server shutdown completed');

        return parent::shutdown();
    }

    public function onPacket(string $rawData, array $clientInfo): void
    {
        $packet = DecodedPacket::decodeFromRaw($rawData);
        if ($packet === false) {
            return;
        }

        // Connless package
        if ($packet->getFlags() & Network::PACKETFLAG_CONNLESS) {
            Console::warn('Connless package received');

            return;
        }

        // Known client (found it slot connection)
        if ($connectionSlot = $this->tryToMatchConnectionSlot($clientInfo)) {
            $connectionSlot->feedConnection($packet);

            return;
        }

        // New client (and slot available)
        if ($connectionSlot = $this->getAvailableConnectionSlot()) {
            $connectionSlot->startConnectionHandshake($clientInfo['address'], $clientInfo['port']);

            return;
        }

        // Server is full...
        PackageEncoder::makeControlMessage(Network::CTRLMSG_CLOSE, 'The server is full')
            ->send($clientInfo['address'], $clientInfo['port']);
    }
}
