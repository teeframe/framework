<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class VoteCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'vote';
    }

    public function getParams(): string
    {
        return 's[result]';
    }

    public function getDescription(): string
    {
        return 'Force the running vote to yes or no';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $yes = match (strtolower($arguments[0])) {
            'yes'   => true,
            'no'    => false,
            default => throw new \InvalidArgumentException('Vote result must be yes or no'),
        };
        $world->getVoteController()->forceResult($yes);
        $this->reply($world, $tee, 'Vote forced to '.($yes ? 'yes' : 'no'), $output);
    }
}
