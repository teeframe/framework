<?php

namespace TeeFrame\Server\Config;

/** @phpstan-import-type Definition from Config */
final readonly class ConfigOption
{
    /** @param 'server'|'world' $scope */
    public function __construct(
        public string $name,
        #[\SensitiveParameter] public int|string $default,
        public string $description,
        public string $scope = 'world',
        public ?int $min = null,
        public ?int $max = null,
        public ?int $bytes = null,
        public bool $restart = false,
        public bool $secret = false,
    ) {}

    /** @return Definition */
    public function getDefinition(): array
    {
        $definition = [
            'default'     => $this->default,
            'description' => $this->description,
            'scope'       => $this->scope,
            'restart'     => $this->restart,
            'secret'      => $this->secret,
        ];

        foreach (['min', 'max', 'bytes'] as $constraint) {
            if ($this->$constraint !== null) {
                $definition[$constraint] = $this->$constraint;
            }
        }

        return $definition;
    }
}
