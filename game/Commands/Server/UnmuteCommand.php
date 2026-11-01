<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class UnmuteCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'unmuteid';
    }

    public function getAliases(): array
    {
        return ['unmuteip'];
    }

    public function getParams(): string
    {
        return 's[ip or id]';
    }

    public function getDescription(): string
    {
        return 'Unmute chat by IP address or world-local player id';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $address = $world->getServer()->resolveClientAddress($world, $arguments[0]);
        $world->getServer()->getChatMutes()->unmute($address);
        $this->reply($world, $tee, 'Unmuted '.$address, $output);
    }
}
