<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class KickCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'kick';
    }

    public function getParams(): string
    {
        return 'i[id] ?r[reason]';
    }

    public function getDescription(): string
    {
        return 'Disconnect a player in this world without banning their address';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $target     = $world->findTee((int) $arguments[0]);
        $connection = $target === null ? null : $world->getServer()->getConnection($target);
        if ($connection === null) {
            throw new \InvalidArgumentException('Invalid player id');
        }
        if ($target === $tee || $connection->rcon()->authenticated) {
            throw new \InvalidArgumentException('Cannot kick yourself or another authenticated administrator');
        }
        $connection->closeConnection(Utf8::truncateBytes($arguments[1] ?? 'Kicked by administrator', 127));
    }
}
