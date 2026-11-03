<?php

namespace TeeFrame\Game\Commands\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Server\Config\Config;

/** @phpstan-import-type Definition from Config */
class ConfigCommand extends AbstractCommand
{
    /** @param Config|class-string<Config> $configClass */
    public function __construct(protected string $name, protected Config|string $configClass = Config::class)
    {
        $this->getDefinition();
    }

    /** @return Definition */
    protected function getDefinition(): array
    {
        return $this->configClass instanceof Config
            ? $this->configClass->getDefinition($this->name)
            : ($this->configClass)::definition($this->name);
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
        return is_int($this->getDefinition()['default']) ? '?i[value]' : '?r[value]';
    }

    public function getDescription(): string
    {
        $definition = $this->getDefinition();

        return $definition['description'].(($definition['restart'] ?? false) ? ' (startup config only)' : '');
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        if (isset($arguments[0])) {
            $world->setConfig($this->name, $arguments[0]);
        }

        $value = ($this->getDefinition()['secret'] ?? false) ? '[hidden]' : $world->getConfigValue($this->name);
        $this->reply($world, $tee, $this->name.' = '.$value, $output);
    }
}
