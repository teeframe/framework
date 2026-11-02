<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class ReloadCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'reload';
    }

    public function getDescription(): string
    {
        return 'Reload the current map and restart this world';
    }

    public function getParams(): string
    {
        return '';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        try {
            $world->reload();
            $text = 'Reloading world';
        } catch (\RuntimeException $exception) {
            $world->consoleWarn('world', 'Reload failed: '.$exception->getMessage());
            $text = 'Unable to reload world';
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
