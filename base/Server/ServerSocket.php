<?php

namespace Base\Server;

use Base\Console;
use Game\GameContext;
use Network\Enums\Network;
use Network\NetworkParams;
use Network\PacketDecoder;
use Network\Packets\ConnectionLessMessage;
use Network\Packets\ControlMessage;
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

        swoole_timer_tick(1000 / NetworkParams::TICKS_PER_SECOND, function (): void {
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
        $packet = PacketDecoder::decodeFromRaw($rawData);
        if ($packet === false) {
            return;
        }

        // Connection-less packet
        if ($packet instanceof ConnectionLessMessage) {
            Console::warn('Connection-less packet received');

            return;
        }

        // Known client (found it slot connection)
        if ($connectionSlot = $this->tryToMatchConnectionSlot($clientInfo)) {
            $connectionSlot->feedConnection($packet);

            return;
        }

        // TODO: Implement ban system

        // New client (and slot available)
        if ($connectionSlot = $this->getAvailableConnectionSlot()) {
            $connectionSlot->handshaker()->startHandshake($clientInfo['address'], $clientInfo['port']);

            return;
        }

        // Server is full...
        $this->sendto(
            $clientInfo['address'],
            $clientInfo['port'],
            (new ControlMessage(Network::CTRLMSG_CLOSE, 'The server is full'))->encodeToSend()
        );
    }
}
