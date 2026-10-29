<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Server\Console;

class ChangeMapCommand extends AbstractCommand
{
    public function __construct(
        protected string $mapName,
        protected string $reason = '',
    ) {
    }

    public function getName(): string
    {
        return 'change_map ' . $this->mapName;
    }

    public function getType(): int
    {
        return self::TYPE_VOTE;
    }

    public function getDescription(): string
    {
        return 'Change map to ' . $this->mapName;
    }

    /**
     * @param  string[]  $matches
     */
    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        try {
            $world->reload(mapName: $this->mapName);
            $text = 'Changing map to '.$this->mapName;
            if ($this->reason !== '') {
                $text .= ' ('.$this->reason.')';
            }
        } catch (\RuntimeException $exception) {
            Console::warn('World='.$world->identifier.' Map change failed: '.$exception->getMessage());
            $text = 'Unable to change map to '.$this->mapName;
        }

        foreach ($world->getTees() as $recipient) {
            $world->getServer()->sendToTee($world, $recipient->teeIndex, new SvChatChunk(
                team: 0,
                clientId: -1,
                text: $text,
            ));
        }
    }
}
