<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class ListCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'list';
    }

    public function getParams(): string
    {
        return '?s[filter]';
    }

    public function getDescription(): string
    {
        return 'List players in this world, optionally filtered by name';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $players = array_filter($world->getTees(), fn (AbstractTee $player): bool => ! isset($arguments[0]) || mb_stripos($player->name, $arguments[0], encoding: 'UTF-8') !== false);
        $this->reply($world, $tee, count($players).' player(s) found:', $output);
        foreach ($players as $player) {
            $this->reply($world, $tee, $player->teeIndex.': '.$player->name, $output);
        }
    }
}
