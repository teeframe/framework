<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class HelpCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
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
        return 'Show usage and help for a chat command';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (! isset($arguments[0])) {
            $this->reply($world, $tee, 'Use cmdlist to list commands, or help <command> to show usage.', $output);

            return;
        }

        $command = $world->findCommand(ltrim($arguments[0], '/'), self::TYPE_CHAT);

        if ($command === null) {
            $this->reply($world, $tee, 'Command not found', $output);

            return;
        }

        $usage = '/'.$command->getName().($command->getParams() !== '' ? ' '.$command->getParams() : '');
        $this->reply($world, $tee, $usage.' - '.$command->getHelpText(), $output);
    }
}
