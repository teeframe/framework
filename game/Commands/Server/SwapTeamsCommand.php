<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;

class SwapTeamsCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'swap_teams';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Swap red and blue players without moving spectators';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (! $world->getGameController()->isTeamplay()) {
            throw new \InvalidArgumentException('This gamemode does not use teams');
        }

        foreach ($world->getTees() as $player) {
            if ($player instanceof PlayerTee && $player->team !== GameConstants::TEAM_SPECTATORS) {
                $player->setTeam($player->team ^ 1);
            }
        }
    }
}
