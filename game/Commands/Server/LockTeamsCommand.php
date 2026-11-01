<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class LockTeamsCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'lock_teams';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Lock or unlock player-initiated team changes';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $controller              = $world->getGameController();
        $controller->teamsLocked = ! $controller->teamsLocked;
        $world->broadcast(new SvChatChunk(0, -1, $controller->teamsLocked ? 'Teams were locked' : 'Teams were unlocked'));
    }
}
