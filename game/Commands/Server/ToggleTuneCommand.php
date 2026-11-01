<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class ToggleTuneCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'toggle_tune';
    }

    public function getParams(): string
    {
        return 's[tuning] f[first] f[second]';
    }

    public function getDescription(): string
    {
        return 'Toggle a tuning value between two values';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $tuning = $world->getTuneController();
        $name   = strtolower($arguments[0]);
        $first  = (float) $arguments[1];
        $second = (float) $arguments[2];
        $tuning->set($name, round($tuning->get($name) * 100) === round($first * 100) ? $second : $first);
        $world->getServer()->sendToWorld($world, $tuning->getTuneParamsChunk(), includeReady: true);
        $this->reply($world, $tee, sprintf('%s %.2f', $name, $tuning->get($name)), $output);
    }
}
