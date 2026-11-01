<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Server\Console;

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
        return 'Reload';
    }

    public function getParams(): string
    {
        return '';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments): void
    {
        try {
            $world->reload();
            $text = 'Reloading world';
        } catch (\RuntimeException $exception) {
            Console::warn('World='.$world->identifier.' Reload failed: '.$exception->getMessage());
            $text = 'Unable to reload world';
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
