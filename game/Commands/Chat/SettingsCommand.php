<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class SettingsCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'settings';
    }

    public function getParams(): string
    {
        return '?s[setting]';
    }

    public function getDescription(): string
    {
        return 'Show gameplay settings: teams, collision, hooking, pause';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $controller = $world->getGameController();
        $tuning     = $world->getTuneController();
        $settings   = [
            'teams'     => ($controller->isTeamplay() ? 'Teamplay' : 'Individual play').'; team changes '.($controller->teamsLocked ? 'locked' : 'unlocked'),
            'collision' => 'Player collision is '.($tuning->playerCollision !== 0 ? 'enabled' : 'disabled'),
            'hooking'   => 'Player hooking is '.($tuning->playerHooking !== 0 ? 'enabled' : 'disabled'),
            'pause'     => $world->isPaused() ? 'Game is paused' : 'Game is unpaused',
        ];
        if (isset($arguments[0])) {
            $name = strtolower($arguments[0]);
            $this->reply($world, $tee, $settings[$name] ?? 'Unknown setting. Available: '.implode(', ', array_keys($settings)), $output);

            return;
        }
        foreach ($settings as $message) {
            $this->reply($world, $tee, $message, $output);
        }
    }
}
