<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class HelpCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'help';
    }

    public function getParams(): string
    {
        return '?s[command]';
    }

    public function getDescription(): string
    {
        return 'Show help for a server command; use cmdlist to list commands';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (! isset($arguments[0])) {
            $this->reply($world, $tee, 'Use cmdlist to list commands, or help <command> to show usage.', $output);

            return;
        }

        $name = strtolower($arguments[0]);

        $info = $world->getServer()->getRcon()->getCommandInfo($world)[$name] ?? null;

        if ($info === null) {
            throw new \InvalidArgumentException('Unknown command: '.$name);
        }

        $this->reply($world, $tee, $name.($info['params'] !== '' ? ' '.$info['params'] : '').' - '.$info['help'], $output);
    }
}
