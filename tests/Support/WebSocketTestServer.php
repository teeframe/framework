<?php

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../Pest.php';

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Sockets\AbstractSocket;
use TeeFrame\Server\Sockets\UdpSocket;
use TeeFrame\Server\Sockets\WebSocketSocket;

class WebSocketTestServer extends AbstractServerInstance
{
    protected function boot(): void
    {
        $controller     = new TestGameController($this->tickHandler);
        $this->worlds[] = new TestWorldWithController($this->tickHandler, new Map(__DIR__.'/../dm1.map'), $controller, $this, slotsLimit: 2);
        $host           = $_SERVER['argv'][2] ?? '127.0.0.1';
        $webSocket      = new WebSocketSocket($host, 0);
        $this->sockets  = ($_SERVER['argv'][1] ?? '') === 'websocket'
            ? [$webSocket]
            : [new UdpSocket($host, 0), $webSocket, new UdpSocket($host, 0)];
    }

    protected function doTick(): void
    {
        if ($this->tickHandler->get() === 0) {
            echo json_encode(array_map(fn (AbstractSocket $socket): int => $socket->port, $this->sockets), JSON_THROW_ON_ERROR).PHP_EOL;
            fflush(STDOUT);
        }
        parent::doTick();

        // Bound the subprocess if a client test fails before terminating it.
        if (! $this->shuttingDown && $this->tickHandler->get() >= 250) {
            $this->shutdown();
        }
    }

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->worlds[0];
    }
}

(new WebSocketTestServer(new TickHandler, 'WebSocket test'))->start();
