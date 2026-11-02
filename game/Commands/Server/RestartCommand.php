<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;

class RestartCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'restart';
    }

    public function getParams(): string
    {
        return '?i[seconds]';
    }

    public function getDescription(): string
    {
        return 'Restart the round now or after a countdown; 0 cancels the countdown';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $controller = $world->getGameController();
        if (! $controller instanceof PvpGameController) {
            throw new \InvalidArgumentException('This gamemode does not support round restarts');
        }
        if (! isset($arguments[0])) {
            $controller->setWarmup(0);
            $controller->startRound();

            return;
        }
        $seconds = (int) $arguments[0];
        if ($seconds < 0 || $seconds > 3600) {
            throw new \InvalidArgumentException('Restart delay must be between 0 and 3600 seconds');
        }
        $controller->setWarmup($seconds);
    }
}
