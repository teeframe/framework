<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class KillPlayerCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'kill_pl';
    }

    public function getParams(): string
    {
        return 'i[id] ?r[reason]';
    }

    public function getDescription(): string
    {
        return 'Kill a player\'s character in this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $target = $world->findTee((int) $arguments[0]);

        if ($target?->character === null) {
            throw new \InvalidArgumentException('Player has no living character');
        }

        if ($world->isPaused()) {
            throw new \InvalidArgumentException('Cannot kill a player while the world is paused');
        }

        $target->character->die($target->teeIndex, GameConstants::WEAPON_GAME);
        $message = 'Admin killed \''.$target->name.'\''.(isset($arguments[1]) ? ' ('.$arguments[1].')' : '');
        $world->broadcast(new SvChatChunk(0, -1, Utf8::truncateBytes($message, 511)));
    }
}
