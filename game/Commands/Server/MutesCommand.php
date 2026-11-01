<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class MutesCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'mutes';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'List active server-wide chat mutes';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $mutes = $world->getServer()->getChatMutes()->getMutes();
        $this->reply($world, $tee, count($mutes).' active mute(s)', $output);
        foreach ($mutes as $address => $mute) {
            $this->reply($world, $tee, $address.' ('.max(0, $mute['expiry'] - time()).' second(s) left) '.$mute['reason'], $output);
        }
    }
}
