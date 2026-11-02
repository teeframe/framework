<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;

class ShuffleTeamsCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'shuffle_teams';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Randomly distribute active players between red and blue';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (! $world->getGameController()->isTeamplay()) {
            throw new \InvalidArgumentException('This gamemode does not use teams');
        }

        $players = array_values(array_filter($world->getTees(), fn (AbstractTee $player): bool => $player instanceof PlayerTee && $player->team !== GameConstants::TEAM_SPECTATORS));
        shuffle($players);

        foreach ($players as $index => $player) {

            $player->setTeam($index % 2);
        }
    }
}
