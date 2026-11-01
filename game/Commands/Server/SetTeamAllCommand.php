<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;

class SetTeamAllCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'set_team_all';
    }

    public function getParams(): string
    {
        return 'i[team]';
    }

    public function getDescription(): string
    {
        return 'Move all players in this world to one team';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $team = max(-1, min(1, (int) $arguments[0]));
        foreach ($world->getTees() as $player) {
            if ($player instanceof PlayerTee) {
                $player->setTeam($team);
            }
        }
    }
}
