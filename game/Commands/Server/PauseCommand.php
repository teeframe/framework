<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;

class PauseCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'pause';
    }

    public function getAliases(): array
    {
        return ['pause_game'];
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Pause or unpause this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $controller = $world->getGameController();

        if ($controller instanceof PvpGameController) {
            $controller->togglePause();
        } else {
            $world->setPaused(! $world->isPaused());
        }

        $this->reply($world, $tee, $world->isPaused() ? 'Game is paused' : 'Game is unpaused', $output);
    }
}
