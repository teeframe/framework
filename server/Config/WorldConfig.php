<?php

namespace TeeFrame\Server\Config;

class WorldConfig
{
    public const PARAMS = 's[identifier] s[map] i[client_limit] i[spectator_slots] ?s[game_type] ?s[config_file]';

    public readonly Config $settings;

    /** @param class-string<Config> $configClass */
    public function __construct(
        public readonly string $identifier,
        string $map,
        public readonly int $slotsLimit,
        public readonly int $spectatorSlots,
        ?string $gameType = null,
        string $configClass = Config::class,
    ) {
        if (trim($identifier) === '' || str_contains($identifier, "\0") || ! mb_check_encoding($identifier, 'UTF-8')) {
            throw new \InvalidArgumentException('World name must be non-blank UTF-8 without zero bytes');
        }

        if ($slotsLimit < 1 || $slotsLimit > 64 || $spectatorSlots < 0 || $spectatorSlots > $slotsLimit) {
            throw new \InvalidArgumentException('World client limit must be between 1 and 64, with spectator slots between 0 and the client limit');
        }

        $this->settings = new $configClass(['sv_world_map' => $map]);

        if ($gameType !== null) {
            $this->settings->set('sv_world_game_type', $gameType);
        }
    }

    /**
     * @param  list<string>  $arguments
     * @param  class-string<Config>  $configClass
     */
    public static function fromArguments(array $arguments, string $configClass = Config::class): self
    {
        return new self($arguments[0], $arguments[1], (int) $arguments[2], (int) $arguments[3], $arguments[4] ?? null, $configClass);
    }
}
