<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class ExecCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'exec';
    }

    public function getParams(): string
    {
        return 's[file]';
    }

    public function getDescription(): string
    {
        return 'Read server and world settings from a configuration file';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $world->loadConfig($arguments[0]);
        $this->reply($world, $tee, 'Loaded configuration: '.$arguments[0], $output);
    }
}
