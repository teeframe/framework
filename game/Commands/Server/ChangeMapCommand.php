<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Server\Console;

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
        return 'Change map';
    }

    public function getParams(): string
    {
        return 's[name] ?r[reason]';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments): void
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
            Console::warn('World='.$world->identifier.' Map change failed: '.$exception->getMessage());
            $text = 'Unable to change map'.($mapName !== '' ? ' to '.$mapName : '');
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
