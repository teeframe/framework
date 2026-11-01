<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\IpAddress;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class UnbanCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'unban';
    }

    public function getParams(): string
    {
        return 's[ip or index]';
    }

    public function getDescription(): string
    {
        return 'Remove a ban by IP address or its index in bans';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $bans    = $world->getServer()->getBanList();
        $address = IpAddress::normalize($arguments[0]);
        if ($address === null) {
            $index   = filter_var($arguments[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $address = $index === false ? null : (array_keys($bans->getBans())[$index] ?? null);
        }
        if ($address === null || ! $bans->isBanned($address)) {
            throw new \InvalidArgumentException('Ban not found');
        }
        $bans->unban($address);
        $this->reply($world, $tee, 'Unbanned '.$address, $output);
    }
}
