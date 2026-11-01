<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class TuneResetCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'tune_reset';
    }

    public function getParams(): string
    {
        return '?s[tuning]';
    }

    public function getDescription(): string
    {
        return 'Reset all tuning values or one tuning value in this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $tuning = $world->getTuneController();
        $tuning->reset(isset($arguments[0]) ? strtolower($arguments[0]) : null);
        $world->getServer()->sendToWorld($world, $tuning->getTuneParamsChunk(), includeReady: true);
        $this->reply($world, $tee, 'Tuning reset', $output);
    }
}
