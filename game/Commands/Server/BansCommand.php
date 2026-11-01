<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class BansCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'bans';
    }

    public function getParams(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'List active server-wide IP bans';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $bans = array_values($world->getServer()->getBanList()->getBans());
        $this->reply($world, $tee, count($bans).' active ban(s)', $output);
        foreach ($bans as $index => $ban) {
            $duration = $ban->expiry === null ? 'permanent' : (int) ceil(($ban->expiry - time()) / 60).' minute(s) left';
            $this->reply($world, $tee, $index.': '.$ban->address.' ('.$duration.') '.$ban->reason, $output);
        }
    }
}
