<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class RulesCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'rules';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Show this world\'s rules';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        foreach ($world->getRules() ?: ['No rules configured.'] as $rule) {
            $this->reply($world, $tee, $rule, $output);
        }
    }
}
