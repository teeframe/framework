<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvVoteOptionAddChunk;

class AddMapVotesCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'add_map_votes';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Add vote options for maps in the current map directory';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if ($world->getMap()->getPath() === '') {
            throw new \InvalidArgumentException('Current map has no source directory');
        }

        $votes = $world->getVoteController();
        $names = [];

        foreach (new \DirectoryIterator(dirname($world->getMap()->getPath())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'map') {
                $names[] = $file->getBasename('.map');
            }
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $added = 0;

        foreach ($names as $name) {
            $description = 'Map: '.$name;

            if ($name === '' || strlen($description) > 63 || ! mb_check_encoding($name, 'UTF-8') || $votes->getVoteOption($description) !== null) {
                continue;
            }

            $command = 'sv_world_map "'.addcslashes($name, '\\"').'"';

            if ($votes->resolveCommands($world, $command) === null) {
                continue;
            }

            $votes->addVoteOption($description, $command);
            $world->getServer()->sendToWorld($world, new SvVoteOptionAddChunk($description), includeReady: true);
            $added++;
        }

        $world->consoleInfo('vote', 'Map vote options added Count='.$added);

        $this->reply($world, $tee, 'Added '.$added.' map vote option(s)', $output);
    }
}
