<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class InfoCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'info';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Show server and world information';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $this->reply($world, $tee, $world->getServer()->getName(), $output);
        $this->reply($world, $tee, 'World='.$world->identifier.' Map='.$world->getMap()->getName().' Gametype='.$world->getGameController()->getGameType().' Players='.count($world->getTees()).'/'.$world->getSlotsLimit(), $output);

        $motd = str_replace('\\n', "\n", $world->getConfig()->getString('sv_world_motd'));

        foreach (explode("\n", $motd) as $line) {
            if (trim($line) !== '') {
                $this->reply($world, $tee, $line, $output);
            }
        }
    }
}
