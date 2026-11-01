<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;

class StatusCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'status';
    }

    public function getParams(): string
    {
        return '?r[filter]';
    }

    public function getDescription(): string
    {
        return 'List players, addresses and connection states in this world';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        foreach ($world->getServer()->getConnections() as $connection) {
            $player = $connection->playerTee();
            if ($connection->world() !== $world || (isset($arguments[0]) && mb_stripos($player->name, $arguments[0], encoding: 'UTF-8') === false)) {
                continue;
            }
            $address = str_contains($connection->destinationAddress, ':') ? '['.$connection->destinationAddress.']' : $connection->destinationAddress;
            $this->reply($world, $tee, 'id='.$player->teeIndex.' name='.$player->name.' addr='.$address.':'.$connection->destinationPort.' state='.$connection->state.' latency='.$player->latency, $output);
        }
    }
}
