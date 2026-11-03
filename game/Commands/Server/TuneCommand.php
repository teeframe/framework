<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class TuneCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'tune';
    }

    public function getParams(): string
    {
        return 's[tuning] ?f[value]';
    }

    public function getDescription(): string
    {
        return 'Show or change a tuning value in this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $tuning = $world->getTuneController();
        $name   = strtolower($arguments[0]);

        if (isset($arguments[1])) {
            $world->setTuning($name, (float) $arguments[1]);
        }

        $this->reply($world, $tee, sprintf('%s %.2f', $name, $tuning->get($name)), $output);
    }
}
