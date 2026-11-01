<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class MuteCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'muteid';
    }

    public function getAliases(): array
    {
        return ['muteip'];
    }

    public function getParams(): string
    {
        return 's[ip or id] i[seconds] ?r[reason]';
    }

    public function getDescription(): string
    {
        return 'Mute chat and whispers by IP address or world-local player id';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $address = $world->getServer()->resolveClientAddress($world, $arguments[0]);
        $seconds = (int) $arguments[1];
        $world->getServer()->getChatMutes()->mute($address, $seconds, Utf8::truncateBytes($arguments[2] ?? 'Muted by administrator', 127));
        $this->reply($world, $tee, 'Muted '.$address.' for '.$seconds.' second(s)', $output);
    }
}
