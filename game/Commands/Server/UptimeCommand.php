<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class UptimeCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'uptime';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Show how long the server has been running';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $seconds = $world->getServer()->getUptime();
        $this->reply($world, $tee, sprintf('Uptime: %d days, %02d:%02d:%02d', intdiv($seconds, 86400), intdiv($seconds, 3600) % 24, intdiv($seconds, 60) % 60, $seconds % 60), $output);
    }
}
