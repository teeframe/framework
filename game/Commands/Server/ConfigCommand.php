<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Server\Config\Config;

class ConfigCommand extends AbstractCommand
{
    /** @param class-string<Config> $configClass */
    public function __construct(protected string $name, protected string $configClass = Config::class)
    {
        $configClass::definition($name);
    }

    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParams(): string
    {
        return is_int(($this->configClass)::definition($this->name)['default']) ? '?i[value]' : '?r[value]';
    }

    public function getDescription(): string
    {
        $definition = ($this->configClass)::definition($this->name);

        return $definition['description'].(($definition['restart'] ?? false) ? ' (startup config only)' : '');
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (isset($arguments[0])) {
            $world->setConfig($this->name, $arguments[0]);
        }

        $value = (($this->configClass)::definition($this->name)['secret'] ?? false) ? '[hidden]' : $world->getConfigValue($this->name);
        $this->reply($world, $tee, $this->name.' = '.$value, $output);
    }
}
