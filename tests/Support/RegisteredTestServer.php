<?php

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../Pest.php';

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\Master\MasterServer;
use TeeFrame\Server\Master\MasterServerHttpClient;

class LocalRegistrationConfig extends Config
{
    protected const DEFINITIONS = [
        'sv_port' => ['default' => 0, 'min' => 0, 'max' => 65535, 'scope' => 'server', 'restart' => true, 'description' => 'Ephemeral UDP port for the local integration server'],
    ] + parent::DEFINITIONS;
}

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
    protected int|false $shutdownTimer = false;

    protected function createMasterServer(): MasterServer
    {
        $arguments = $_SERVER['argv'] ?? [];
        assert(is_array($arguments));
        $url = $arguments[1] ?? null;
        assert(is_string($url));

        return new MasterServer($this->tickHandler, new LocalMasterServerHttpClient($url));
    }

    protected function boot(): void
    {
        parent::boot();

        $arguments = $_SERVER['argv'] ?? [];
        assert(is_array($arguments));

        // Bound the subprocess even if the test master never accepts registration.
        $this->shutdownTimer = swoole_timer_after(($arguments[2] ?? '') === 'signal' ? 3000 : 1000, function (): void {
            $this->shutdownTimer = false;
            echo 'registration-test-ticks='.$this->tickHandler->getCurrentTick().PHP_EOL;
            $this->shutdown();
        });
    }

    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        $world = new TestWorldWithController(
            $this->tickHandler,
            new Map(__DIR__.'/../'.$config->settings->getString('sv_world_map').'.map'),
            new TestGameController($this->tickHandler),
            $this,
            slotsLimit: $config->slotsLimit,
            config: $config->settings,
            spectatorSlots: $config->spectatorSlots,
        );
        $world->identifier = $config->identifier;

        return $world;
    }

    protected function stopTicking(): void
    {
        parent::stopTicking();
        if ($this->shutdownTimer !== false) {
            swoole_timer_clear($this->shutdownTimer);
            $this->shutdownTimer = false;
        }
    }
}

if (! isset($argv[1])) {
    throw new InvalidArgumentException('Missing local master server URL');
}

$config = new LocalRegistrationConfig;
$config->loadFile(__DIR__.'/registered-server.cfg');
$config->set('sv_bind_address', $argv[3] ?? '127.0.0.1');

(new RegisteredTestServer(new TickHandler, config: $config))->start();
