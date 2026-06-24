<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class PingCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'ping';
    }

    public function getPattern(): string
    {
        return '/^\/ping$/i';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: 'Pong!',
        ));
    }
}
