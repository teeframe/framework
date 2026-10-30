<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Server\Console;

class ChangeGameModeCommand extends AbstractCommand
{
    public function __construct(
        protected string $gameMode,
        protected string $reason = '',
        protected ?string $mapName = null,
    ) {}

    public function getName(): string
    {
        return 'change_gamemode '.strtolower($this->gameMode);
    }

    public function getType(): int
    {
        return self::TYPE_VOTE;
    }

    public function getDescription(): string
    {
        return 'Change gamemode to '.strtoupper($this->gameMode);
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        try {
            $world->reload(mapName: $this->mapName, gameMode: $this->gameMode);
            $text = 'Changing gamemode to '.strtoupper($this->gameMode);
            if ($this->reason !== '') {
                $text .= ' ('.$this->reason.')';
            }
        } catch (\RuntimeException $exception) {
            Console::warn('World='.$world->identifier.' Gamemode change failed: '.$exception->getMessage());
            $text = 'Unable to change gamemode to '.strtoupper($this->gameMode);
        }

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
