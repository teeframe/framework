<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Server\Console;

class ReloadCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'reload';
    }

    public function getType(): int
    {
        return self::TYPE_VOTE;
    }

    public function getDescription(): string
    {
        return 'Reload';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
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
