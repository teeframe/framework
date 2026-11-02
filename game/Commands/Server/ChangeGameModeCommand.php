<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class ChangeGameModeCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'change_gamemode';
    }

    public function getDescription(): string
    {
        return 'Change this world\'s gamemode, optionally loading another map';
    }

    public function getParams(): string
    {
        return 's[mode] ?s[map] ?r[reason]';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $gameMode = $arguments[0];
        $mapName  = $arguments[1] ?? '';
        $reason   = $arguments[2] ?? '';

        try {
            $world->reload(mapName: $mapName === '' ? null : $mapName, gameMode: $gameMode);
            $text = 'Changing gamemode to '.strtoupper($gameMode);

            if ($reason !== '') {
                $text .= ' ('.$reason.')';
            }
        } catch (\RuntimeException $exception) {
            $world->consoleWarn('world', 'Gamemode change failed: '.$exception->getMessage());
            throw new \RuntimeException('Unable to change gamemode'.($gameMode !== '' ? ' to '.strtoupper($gameMode) : ''), previous: $exception);
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
