<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;

class ClearVotesCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'clear_votes';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Clear all vote options in this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $world->getVoteController()->clearVoteOptions();
        $world->getServer()->sendToWorld($world, new SvVoteClearOptionsChunk, includeReady: true);
        $this->reply($world, $tee, 'Vote options cleared', $output);
    }
}
