<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class UnbanAllCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'unban_all';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Remove every server-wide IP ban';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $world->getServer()->getBanList()->clear();
        $this->reply($world, $tee, 'All bans removed', $output);
    }
}
