<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class PingCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'ping';
    }

    public function getDescription(): string
    {
        return 'Check that the server responds';
    }

    public function getParams(): string
    {
        return '';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: 'Pong!',
        ));
    }
}
