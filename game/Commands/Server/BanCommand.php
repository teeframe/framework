<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Core\IpAddress;
use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class BanCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'ban';
    }

    public function getParams(): string
    {
        return 's[ip or id] ?i[minutes] r[reason]';
    }

    public function getDescription(): string
    {
        return 'Ban an IP address or world-local player id; default 30 minutes, 0 permanent';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $server      = $world->getServer();
        $address     = $server->resolveClientAddress($world, $arguments[0]);
        $connections = array_filter($server->getConnections(), fn ($connection): bool => IpAddress::normalize($connection->destinationAddress) === $address);
        foreach ($connections as $connection) {
            if ($connection->playerTee() === $tee || $connection->rcon()->authenticated) {
                throw new \InvalidArgumentException('Cannot ban your address or an authenticated administrator\'s address');
            }
        }
        $minutes = (int) ($arguments[1] ?? 30);
        if ($minutes < 0 || $minutes > 525600) {
            throw new \InvalidArgumentException('Ban duration must be between 0 and 525600 minutes');
        }
        $reason = Utf8::truncateBytes($arguments[2] ?? 'Banned by administrator', 127);
        $server->getBanList()->ban($address, $minutes === 0 ? null : $minutes * 60, $reason);
        foreach ($connections as $connection) {
            $connection->closeConnection($reason);
        }
        $this->reply($world, $tee, 'Banned '.$address.($minutes === 0 ? ' permanently' : ' for '.$minutes.' minute(s)'), $output);
    }
}
