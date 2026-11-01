<?php

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../Pest.php';

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Master\MasterServer;
use TeeFrame\Server\Master\MasterServerHttpClient;
use TeeFrame\Server\Sockets\UdpSocket;

class LocalMasterServerHttpClient extends MasterServerHttpClient
{
    public function __construct(protected string $localUrl) {}

    public function post(string $url, int $family, array $headers, string $body, float $timeout = 5.0, string $localAddress = ''): array
    {
        return parent::post($this->localUrl, $family, $headers, $body, $timeout, $localAddress);
    }
}

class RegisteredTestServer extends AbstractServerInstance
{
    protected function createMasterServer(): MasterServer
    {
        return new MasterServer($this->tickHandler, new LocalMasterServerHttpClient($_SERVER['argv'][1]));
    }

    protected function boot(): void
    {
        $this->worlds[]  = new TestWorld($this->tickHandler, new Map(__DIR__.'/../dm1.map'), $this);
        $this->sockets[] = new UdpSocket($_SERVER['argv'][3] ?? '127.0.0.1', 0);

        // Bound the subprocess even if the test master never accepts registration.
        swoole_timer_after(($_SERVER['argv'][2] ?? '') === 'signal' ? 3000 : 1000, function (): void {
            echo 'registration-test-ticks='.$this->tickHandler->get().PHP_EOL;
            $this->shutdown();
        });
    }

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->worlds[0];
    }
}

if (! isset($argv[1])) {
    throw new InvalidArgumentException('Missing local master server URL');
}

(new RegisteredTestServer(new TickHandler, 'Registration integration test', registerOnMasterServer: true))->start();
