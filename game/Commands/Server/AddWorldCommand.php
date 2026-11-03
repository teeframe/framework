<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Server\Config\WorldConfig;

class AddWorldCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return 'sv_world_add';
    }

    public function getParams(): string
    {
        return WorldConfig::PARAMS;
    }

    public function getDescription(): string
    {
        return 'Add a world with its own map, client limit, spectator slots and optional gametype and config file';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $config = WorldConfig::fromArguments($arguments, config: $world->getServer()->getConfig());

        if (isset($arguments[5])) {
            $config->settings->loadFile($arguments[5], worldOnly: true, activatePvpSettings: $world->getServer()->activatePvpSettings);
        }

        $world->getServer()->addWorldFromConfig($config);
        $this->reply($world, $tee, 'Added world: '.$config->identifier, $output);
    }
}
