<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class TuneDumpCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'tune_dump';
    }

    public function getAliases(): array
    {
        return ['tunes'];
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'List this world\'s tuning values';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        foreach ($world->getTuneController()->getValues() as $name => $value) {
            $this->reply($world, $tee, sprintf('%s %.2f', $name, $value), $output);
        }
    }
}
