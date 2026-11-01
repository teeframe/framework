<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class SayCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'say';
    }

    public function getParams(): string
    {
        return 'r[message]';
    }

    public function getDescription(): string
    {
        return 'Send a server chat message to this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $world->broadcast(new SvChatChunk(0, -1, Utf8::truncateBytes($arguments[0], 511)));
    }
}
