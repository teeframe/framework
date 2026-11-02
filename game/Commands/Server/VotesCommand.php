<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class VotesCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'votes';
    }

    public function getParams(): string
    {
        return '?i[page]';
    }

    public function getDescription(): string
    {
        return 'List this world\'s vote options, 20 per page starting at page 0';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $options = $world->getVoteController()->getVoteOptions();
        $page    = (int) ($arguments[0] ?? 0);

        if ($page < 0 || $page > max(0, intdiv(count($options) - 1, 20))) {
            throw new \InvalidArgumentException('Invalid vote-list page');
        }

        $this->reply($world, $tee, count($options).' vote option(s), page '.$page, $output);

        foreach (array_slice($options, $page * 20, 20) as $option) {
            $this->reply($world, $tee, $option->description.' - '.$option->command, $output);
        }
    }
}
