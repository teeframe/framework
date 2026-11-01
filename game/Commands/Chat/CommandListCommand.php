<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class CommandListCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'cmdlist';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'List available chat commands and aliases';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        foreach (self::formatList(array_keys($world->getCommandMap(self::TYPE_CHAT))) as $line) {
            $this->reply($world, $tee, $line, $output);
        }
    }
}
