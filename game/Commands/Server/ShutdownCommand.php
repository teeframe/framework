<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class ShutdownCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'shutdown';
    }

    public function getParams(): string
    {
        return '?r[reason]';
    }

    public function getDescription(): string
    {
        return 'Shut down the server and all worlds';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $server = $world->getServer();
        $reason = Utf8::truncateBytes($arguments[0] ?? 'Server shutdown', 127);

        foreach ($server->getConnections() as $connection) {
            $connection->closeConnection($reason);
        }

        $server->shutdown();
    }
}
