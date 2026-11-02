<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class ChangeMapCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'change_map';
    }

    public function getDescription(): string
    {
        return 'Load another map and restart this world';
    }

    public function getParams(): string
    {
        return 's[name] ?r[reason]';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $mapName = $arguments[0];
        $reason  = $arguments[1] ?? '';
        try {
            $world->reload(mapName: $mapName);
            $text = 'Changing map to '.$mapName;
            if ($reason !== '') {
                $text .= ' ('.$reason.')';
            }
        } catch (\RuntimeException $exception) {
            $world->consoleWarn('world', 'Map change failed: '.$exception->getMessage());
            $text = 'Unable to change map'.($mapName !== '' ? ' to '.$mapName : '');
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
