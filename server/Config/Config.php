<?php

namespace TeeFrame\Server\Config;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\Vote\VoteOption;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Server\Console;

/** @phpstan-type Definition array{default: int|string, description: string, scope: 'server'|'world', min?: int, max?: int, bytes?: int, restart?: bool, secret?: bool} */
class Config
{
    /** @var array<string, Definition> */
    protected const DEFINITIONS = [
        'sv_name'         => ['default' => 'TeeFrame', 'bytes' => 127, 'scope' => 'server', 'description' => 'Server name'],
        'sv_bind_address' => ['default' => '0.0.0.0', 'bytes' => 127, 'scope' => 'server', 'restart' => true, 'description' => 'Address to bind the listening socket to'],
        'sv_port'         => ['default' => 8303, 'min' => 1, 'max' => 65535, 'scope' => 'server', 'restart' => true, 'description' => 'UDP port to listen on'],

        'sv_password'                       => ['default' => '', 'bytes' => 127, 'scope' => 'server', 'secret' => true, 'description' => 'Password required to join the server'],
        'sv_register_on_master_server'      => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'server', 'restart' => true, 'description' => 'Register the server with the master server'],
        'sv_max_clients_per_ip'             => ['default' => 0, 'min' => 0, 'max' => 64, 'scope' => 'server', 'description' => 'Maximum simultaneous connections from one IP address (0 disables the limit)'],
        'sv_kick_ban_duration'              => ['default' => 10, 'min' => 0, 'max' => 1440, 'scope' => 'server', 'description' => 'Default kick ban duration in minutes (0 disconnects without banning)'],
        'sv_rcon_password'                  => ['default' => '', 'bytes' => 127, 'scope' => 'server', 'secret' => true, 'description' => 'Remote console password'],
        'sv_rcon_max_tries'                 => ['default' => 10, 'min' => 0, 'max' => 100, 'scope' => 'server', 'description' => 'Maximum failed RCON authentication attempts (0 disables the limit)'],
        'sv_rcon_ban_duration'              => ['default' => 5, 'min' => 0, 'max' => 1440, 'scope' => 'server', 'description' => 'RCON authentication ban duration in minutes (0 kicks without banning)'],
        'sv_server_info_replies_per_second' => ['default' => 0, 'min' => 0, 'max' => 1000000, 'scope' => 'server', 'description' => 'Maximum server-browser replies per second (0 disables the limit)'],
        'sv_shutdown_when_empty'            => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'server', 'description' => 'Shut down after the last client disconnects'],
        'sv_log_file'                       => ['default' => '', 'bytes' => 4095, 'scope' => 'server', 'description' => 'Append console output to this file (empty disables file logging)'],
        'sv_log_file_output_level'          => ['default' => 0, 'min' => -3, 'max' => 2, 'scope' => 'server', 'description' => 'File logging level (-3 none, -2 error, -1 warn, 0 info, 1 debug, 2 trace)'],
        'sv_console_output_level'           => ['default' => 0, 'min' => -3, 'max' => 2, 'scope' => 'server', 'description' => 'Terminal logging level (-3 none, -2 error, -1 warn, 0 info, 1 debug, 2 trace)'],
        'sv_rcon_output_level'              => ['default' => 0, 'min' => -3, 'max' => 2, 'scope' => 'server', 'description' => 'RCON logging level (-3 none, -2 error, -1 warn, 0 info, 1 debug, 2 trace)'],
        'sv_console_colors'                 => ['default' => 1, 'min' => 0, 'max' => 1, 'scope' => 'server', 'description' => 'Allow terminal colors when output is a terminal'],

        'sv_world_map'          => ['default' => 'dm1', 'bytes' => 127, 'scope' => 'world', 'description' => 'Map name, relative to the current map directory'],
        'sv_world_game_type'    => ['default' => 'dm', 'bytes' => 31, 'scope' => 'world', 'description' => 'Game type supported by this world\'s controller'],
        'sv_world_motd'         => ['default' => '', 'bytes' => 899, 'scope' => 'world', 'description' => 'Message shown when clients join (use \\n for new lines)'],
        'sv_world_welcome'      => ['default' => '', 'bytes' => 255, 'scope' => 'world', 'description' => 'Chat message sent privately to joining players'],
        'sv_world_rules_line1'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'First line shown by /rules'],
        'sv_world_rules_line2'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Second line shown by /rules'],
        'sv_world_rules_line3'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Third line shown by /rules'],
        'sv_world_rules_line4'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Fourth line shown by /rules'],
        'sv_world_rules_line5'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Fifth line shown by /rules'],
        'sv_world_rules_line6'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Sixth line shown by /rules'],
        'sv_world_rules_line7'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Seventh line shown by /rules'],
        'sv_world_rules_line8'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Eighth line shown by /rules'],
        'sv_world_rules_line9'  => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Ninth line shown by /rules'],
        'sv_world_rules_line10' => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Tenth line shown by /rules'],

        'sv_world_join_as_spectator' => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Make new players join as spectators'],
        'sv_world_spam_protection'   => ['default' => 1, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Rate-limit chat, team changes, spectator changes, info changes and vote attempts'],

        'sv_world_inactivity_timeout'         => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Minutes before handling inactive players (0 disables the check)'],
        'sv_world_inactivity_action'          => ['default' => 0, 'min' => 0, 'max' => 2, 'scope' => 'world', 'description' => 'Inactive player action (0 spectate, 1 spectate if a reserved slot is free or kick, 2 kick)'],
        'sv_world_hide_stats_from_spectators' => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Hide health, armor and ammo from spectators'],
        'sv_world_vote_spectate'              => ['default' => 1, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Allow votes to move players to spectators'],
        'sv_world_vote_spectate_rejoin_delay' => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Minutes before a player moved by vote can rejoin'],
        'sv_world_vote_kick'                  => ['default' => 1, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Allow kick votes'],
        'sv_world_vote_kick_min_players'      => ['default' => 0, 'min' => 0, 'max' => 64, 'scope' => 'world', 'description' => 'Minimum active players required for a kick vote'],
        'sv_world_vote_kick_ban_duration'     => ['default' => 10, 'min' => 0, 'max' => 1440, 'scope' => 'world', 'description' => 'Kick-vote ban duration in minutes (0 kicks without banning)'],
        'sv_world_vote_duration'              => ['default' => 25, 'min' => 1, 'max' => 60, 'scope' => 'world', 'description' => 'Vote duration in seconds'],
        'sv_world_vote_delay'                 => ['default' => 60, 'min' => 0, 'max' => 9999, 'scope' => 'world', 'description' => 'Minimum seconds between votes called by the same player'],
        'sv_world_chat_delay'                 => ['default' => 1, 'min' => 0, 'max' => 9999, 'scope' => 'world', 'description' => 'Minimum seconds between chat messages, in addition to the message-length limit'],
        'sv_world_team_change_delay'          => ['default' => 3, 'min' => 0, 'max' => 9999, 'scope' => 'world', 'description' => 'Minimum seconds between team changes'],
        'sv_world_info_change_delay'          => ['default' => 5, 'min' => 0, 'max' => 9999, 'scope' => 'world', 'description' => 'Minimum seconds between name, clan or appearance changes'],

        'sv_world_self_kill_delay' => ['default' => 3, 'min' => 0, 'max' => 9999, 'scope' => 'world', 'description' => 'Minimum seconds between self-kill requests'],

        'sv_world_pvp_warmup'                    => ['default' => 0, 'min' => 0, 'scope' => 'world', 'description' => 'Warmup duration in seconds'],
        'sv_world_pvp_unpause_timer'             => ['default' => 0, 'min' => 0, 'scope' => 'world', 'description' => 'Countdown in seconds before resuming a paused round'],
        'sv_world_pvp_team_damage'               => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Allow damage between teammates'],
        'sv_world_pvp_map_rotation'              => ['default' => '', 'bytes' => 767, 'scope' => 'world', 'description' => 'Map names to rotate through, separated by spaces, commas or semicolons'],
        'sv_world_pvp_rounds_per_map'            => ['default' => 1, 'min' => 1, 'max' => 100, 'scope' => 'world', 'description' => 'Rounds to play before rotating to the next map'],
        'sv_world_pvp_swap_teams_between_rounds' => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Swap red and blue teams between rounds'],
        'sv_world_pvp_score_limit'               => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Round score limit (0 disables the limit)'],
        'sv_world_pvp_time_limit'                => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Round time limit in minutes (0 disables the limit)'],
        'sv_world_pvp_team_balance_time'         => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Minutes before automatically balancing teams (0 disables balancing)'],
    ];

    /** @var array<string, Definition> */
    protected array $schema;

    /** @var array<string, int|string> */
    protected array $values = [];

    /** @var array<string, WorldConfig> */
    protected array $worlds = [];

    /** @var array<string, VoteOption> */
    protected array $voteOptions = [];

    /** @var list<string> */
    protected array $loadedFiles = [];

    /** @param array<string, int|string> $values */
    public function __construct(#[\SensitiveParameter] array $values = [], protected ?self $parent = null)
    {
        $this->schema = static::DEFINITIONS;

        foreach ($values as $name => $value) {
            $this->set($name, $value);
        }
    }

    public static function fromFile(string $path): static
    {
        $config = new static;
        $config->loadFile($path);

        return $config;
    }

    public function withParent(self $parent): self
    {
        if (is_a($this, $parent::class)) {
            $config         = clone $this;
            $config->schema = $this->getDefinitions() + $parent->getDefinitions();
        } else {
            // Inherit the parent's legacy subclass defaults without losing locally registered options.
            $config = $parent->createLayer();
            $config->schema += $this->getDefinitions();
            $config->worlds      = $this->worlds;
            $config->voteOptions = $this->voteOptions;
            $config->loadedFiles = $this->loadedFiles;

            foreach ($this->values as $name => $value) {
                $config->set($name, $value);
            }
        }

        $config->parent = $parent;

        return $config;
    }

    public function createLayer(): static
    {
        $config              = clone $this;
        $config->schema      = $this->getDefinitions();
        $config->values      = [];
        $config->worlds      = [];
        $config->voteOptions = [];
        $config->loadedFiles = [];
        $config->parent      = null;

        return $config;
    }

    public function registerOption(#[\SensitiveParameter] ConfigOption $option): void
    {
        $name = strtolower($option->name);

        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Configuration option names must be ASCII identifiers');
        }

        if (isset(self::DEFINITIONS[$name]) || isset($this->getDefinitions()[$name])) {
            throw new \LogicException('Configuration option already defined: '.$name);
        }

        /** @var string $scope */
        $scope = $option->scope;

        if (! in_array($scope, ['server', 'world'], true)) {
            throw new \InvalidArgumentException('Invalid configuration scope for '.$name);
        }

        if (trim($option->description) === '' || ! mb_check_encoding($option->description, 'UTF-8') || str_contains($option->description, "\0")) {
            throw new \InvalidArgumentException('Expected a non-blank UTF-8 description without zero bytes for '.$name);
        }

        if (is_int($option->default)) {
            if ($option->bytes !== null || ($option->min ?? 0) > ($option->max ?? PHP_INT_MAX)) {
                throw new \InvalidArgumentException('Invalid integer constraints for '.$name);
            }
        } elseif ($option->min !== null || $option->max !== null || ($option->bytes !== null && $option->bytes < 0)) {
            throw new \InvalidArgumentException('Invalid string constraints for '.$name);
        }

        $definition = $option->getDefinition();

        if (self::validateDefinitionValue($name, $option->default, $definition) !== $option->default) {
            throw new \InvalidArgumentException('Default value exceeds the byte limit for '.$name);
        }

        $this->schema[$name] = $definition;
    }

    /** @return list<string> */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /** @return list<WorldConfig> */
    public function getWorlds(): array
    {
        return array_values($this->worlds);
    }

    /** @return list<VoteOption> */
    public function getVoteOptions(): array
    {
        $options = [];

        foreach ([...($this->parent?->getVoteOptions() ?? []), ...array_values($this->voteOptions)] as $option) {
            $options[strtolower($option->description)] = $option;
        }

        return array_values($options);
    }

    /** @return array<string, Definition> */
    public static function definitions(bool $activatePvpSettings = true): array
    {
        return $activatePvpSettings ? static::DEFINITIONS : array_filter(
            static::DEFINITIONS,
            fn (string $name): bool => ! str_starts_with($name, 'sv_world_pvp_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return Definition */
    public static function definition(string $name, bool $activatePvpSettings = true): array
    {
        $name       = strtolower($name);
        $definition = static::DEFINITIONS[$name] ?? throw new \InvalidArgumentException('Unknown configuration variable: '.$name);

        if (! $activatePvpSettings && str_starts_with($name, 'sv_world_pvp_')) {
            throw new \InvalidArgumentException('PvP settings are disabled: '.$name);
        }

        return $definition;
    }

    /** @return array<string, Definition> */
    public function getDefinitions(bool $activatePvpSettings = true): array
    {
        $definitions = $this->schema + ($this->parent?->getDefinitions() ?? []);

        return $activatePvpSettings ? $definitions : array_filter(
            $definitions,
            fn (string $name): bool => ! str_starts_with($name, 'sv_world_pvp_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return Definition */
    public function getDefinition(string $name, bool $activatePvpSettings = true): array
    {
        $name       = strtolower($name);
        $definition = $this->schema[$name] ?? $this->parent?->getDefinition($name)
            ?? throw new \InvalidArgumentException('Unknown configuration variable: '.$name);

        if (! $activatePvpSettings && str_starts_with($name, 'sv_world_pvp_')) {
            throw new \InvalidArgumentException('PvP settings are disabled: '.$name);
        }

        return $definition;
    }

    public function assertPvpSettingsEnabled(bool $activatePvpSettings): void
    {
        if ($activatePvpSettings) {
            return;
        }

        foreach (array_keys($this->values) as $name) {
            $this->getDefinition($name, activatePvpSettings: false);
        }

        $this->parent?->assertPvpSettingsEnabled(false);

        foreach ($this->worlds as $world) {
            $world->settings->assertPvpSettingsEnabled(false);
        }
    }

    public function has(string $name): bool
    {
        $name = strtolower($name);

        return array_key_exists($name, $this->values) || ($this->parent?->has($name) ?? false);
    }

    public function getInt(string $name, ?int $fallback = null): int
    {
        $value = $this->get($name, $fallback);

        return is_int($value) ? $value : throw new \LogicException('Configuration variable is not an integer: '.$name);
    }

    public function getString(string $name, ?string $fallback = null): string
    {
        $value = $this->get($name, $fallback);

        return is_string($value) ? $value : throw new \LogicException('Configuration variable is not a string: '.$name);
    }

    public function get(string $name, int|string|null $fallback = null): int|string
    {
        $name = strtolower($name);

        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        // Inherit explicit values, but keep defaults from the nearest schema defining the setting.
        if ($this->parent !== null && ($this->parent->has($name) || ! isset($this->schema[$name]))) {
            return $this->parent->get($name, $fallback);
        }

        $definition = $this->getDefinition($name);

        return $fallback ?? $definition['default'];
    }

    public function set(string $name, #[\SensitiveParameter] int|string $value): void
    {
        $name                = strtolower($name);
        $this->values[$name] = $this->validateValue($name, $value);
    }

    public function validateValue(string $name, #[\SensitiveParameter] int|string $value): int|string
    {
        return self::validateDefinitionValue(strtolower($name), $value, $this->getDefinition($name));
    }

    public static function validate(string $name, #[\SensitiveParameter] int|string $value): int|string
    {
        return self::validateDefinitionValue(strtolower($name), $value, static::definition($name));
    }

    /** @param Definition $definition */
    protected static function validateDefinitionValue(string $name, #[\SensitiveParameter] int|string $value, #[\SensitiveParameter] array $definition): int|string
    {
        if (is_int($definition['default'])) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer === false || $integer < ($definition['min'] ?? 0) || $integer > ($definition['max'] ?? PHP_INT_MAX)) {
                throw new \InvalidArgumentException('Invalid integer or out-of-range value for '.$name);
            }

            if (in_array($name, ['sv_world_pvp_warmup', 'sv_world_pvp_unpause_timer'], true) && $integer > intdiv(NetworkParams::MAXIMUM_TICK, NetworkParams::TICKS_PER_SECOND)) {
                throw new \InvalidArgumentException('Duration exceeds the server tick range for '.$name);
            }

            return $integer;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Expected a UTF-8 string without zero bytes for '.$name);
        }

        $value = Utf8::truncateBytes($value, $definition['bytes'] ?? PHP_INT_MAX);

        if ($name === 'sv_world_map' && ($value === '' || $value === '.' || $value === '..' || strpbrk($value, "/\\\0") !== false)) {
            throw new \InvalidArgumentException('Invalid map name');
        }

        return $value;
    }

    /** @param list<string> $including */
    public function loadFile(string $path, bool $worldOnly = false, array $including = [], bool $activatePvpSettings = true): void
    {
        $values      = $this->values;
        $worlds      = $this->worlds;
        $voteOptions = $this->voteOptions;
        $loadedFiles = [];

        foreach (ConfigFile::read($path, $including, $worldOnly, static::class, $loadedFiles, config: $this, activatePvpSettings: $activatePvpSettings) as $entry) {
            if ($entry instanceof VoteOption) {
                $voteOptions[strtolower($entry->description)] ??= $entry;

                continue;
            }

            if ($entry instanceof WorldConfig) {
                if (isset($worlds[$entry->identifier])) {
                    Console::error('config', 'Cannot load configuration at '.$path.': duplicate world declaration');

                    continue;
                }

                $worlds[$entry->identifier] = $entry;

                continue;
            }

            $values[$entry['name']] = $entry['value'];
        }

        $this->values      = $values;
        $this->worlds      = $worlds;
        $this->voteOptions = $voteOptions;
        $this->loadedFiles = array_values(array_unique([...$this->loadedFiles, ...$loadedFiles]));
    }
}
