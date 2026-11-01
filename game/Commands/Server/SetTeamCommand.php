<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\NetworkParams;

class SetTeamCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'set_team';
    }

    public function getParams(): string
    {
        return 'i[id] i[team] ?i[delay in minutes]';
    }

    public function getDescription(): string
    {
        return 'Move a player to a team; -1 spectators, 0 red/game, 1 blue';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $target = $world->findTee((int) $arguments[0]);
        if (! $target instanceof PlayerTee) {
            throw new \InvalidArgumentException('Invalid player id');
        }
        $delay = (int) ($arguments[2] ?? 0);
        if ($delay < 0 || $delay > 1440) {
            throw new \InvalidArgumentException('Team-change delay must be between 0 and 1440 minutes');
        }
        $target->setTeam(max(-1, min(1, (int) $arguments[1])));
        $target->teamChangeTick = $world->getCurrentTick() + $delay * 60 * NetworkParams::TICKS_PER_SECOND;
    }
}
