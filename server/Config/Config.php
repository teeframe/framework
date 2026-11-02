<?php

namespace TeeFrame\Server\Config;

use TeeFrame\Core\Utf8;
use TeeFrame\Network\NetworkParams;

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

        'sv_world_map'                       => ['default' => 'dm1', 'bytes' => 127, 'scope' => 'world', 'description' => 'Map name, relative to the current map directory'],
        'sv_world_game_type'                 => ['default' => 'dm', 'bytes' => 31, 'scope' => 'world', 'description' => 'Game type supported by this world\'s controller'],
        'sv_world_motd'                      => ['default' => '', 'bytes' => 899, 'scope' => 'world', 'description' => 'Message shown when clients join (use \\n for new lines)'],
        'sv_world_welcome'                   => ['default' => '', 'bytes' => 255, 'scope' => 'world', 'description' => 'Chat message sent privately to joining players'],
        'sv_world_rules_line1'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'First line shown by /rules'],
        'sv_world_rules_line2'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Second line shown by /rules'],
        'sv_world_rules_line3'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Third line shown by /rules'],
        'sv_world_rules_line4'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Fourth line shown by /rules'],
        'sv_world_rules_line5'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Fifth line shown by /rules'],
        'sv_world_rules_line6'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Sixth line shown by /rules'],
        'sv_world_rules_line7'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Seventh line shown by /rules'],
        'sv_world_rules_line8'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Eighth line shown by /rules'],
        'sv_world_rules_line9'               => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Ninth line shown by /rules'],
        'sv_world_rules_line10'              => ['default' => '', 'bytes' => 127, 'scope' => 'world', 'description' => 'Tenth line shown by /rules'],
        'sv_world_warmup'                    => ['default' => 0, 'min' => 0, 'scope' => 'world', 'description' => 'Warmup duration in seconds'],
        'sv_world_unpause_timer'             => ['default' => 0, 'min' => 0, 'scope' => 'world', 'description' => 'Countdown in seconds before resuming a paused round'],
        'sv_world_team_damage'               => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Allow damage between teammates'],
        'sv_world_map_rotation'              => ['default' => '', 'bytes' => 767, 'scope' => 'world', 'description' => 'Map names to rotate through, separated by spaces, commas or semicolons'],
        'sv_world_rounds_per_map'            => ['default' => 1, 'min' => 1, 'max' => 100, 'scope' => 'world', 'description' => 'Rounds to play before rotating to the next map'],
        'sv_world_swap_teams_between_rounds' => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Swap red and blue teams between rounds'],

        'sv_world_score_limit'       => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Round score limit (0 disables the limit)'],
        'sv_world_time_limit'        => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Round time limit in minutes (0 disables the limit)'],
        'sv_world_join_as_spectator' => ['default' => 0, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Make new players join as spectators'],
        'sv_world_spam_protection'   => ['default' => 1, 'min' => 0, 'max' => 1, 'scope' => 'world', 'description' => 'Rate-limit chat, team changes, spectator changes, info changes and vote attempts'],

        'sv_world_team_balance_time'          => ['default' => 0, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Minutes before automatically balancing teams (0 disables balancing)'],
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
    ];

    /** @var array<string, int|string> */
    protected array $values = [];

    /** @var array<string, WorldConfig> */
    protected array $worlds = [];

    /** @param array<string, int|string> $values */
    public function __construct(#[\SensitiveParameter] array $values = [], protected ?self $parent = null)
    {
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
        $config         = clone $this;
        $config->parent = $parent;

        return $config;
    }

    /** @return list<WorldConfig> */
    public function getWorlds(): array
    {
        return array_values($this->worlds);
    }

    /** @return array<string, Definition> */
    public static function definitions(): array
    {
        return static::DEFINITIONS;
    }

    /** @return Definition */
    public static function definition(string $name): array
    {
        return static::DEFINITIONS[strtolower($name)] ?? throw new \InvalidArgumentException('Unknown configuration variable: '.$name);
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
        if (! array_key_exists($name, $this->values) && $this->parent !== null) {
            return $this->parent->get($name, $fallback);
        }

        $definition = static::definition($name);

        return $this->values[$name] ?? $fallback ?? $definition['default'];
    }

    public function set(string $name, #[\SensitiveParameter] int|string $value): void
    {
        $name                = strtolower($name);
        $this->values[$name] = static::validate($name, $value);
    }

    public static function validate(string $name, #[\SensitiveParameter] int|string $value): int|string
    {
        $name       = strtolower($name);
        $definition = static::definition($name);

        if (is_int($definition['default'])) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer === false || $integer < ($definition['min'] ?? 0) || $integer > ($definition['max'] ?? PHP_INT_MAX)) {
                throw new \InvalidArgumentException('Invalid integer or out-of-range value for '.$name);
            }

            if (in_array($name, ['sv_world_warmup', 'sv_world_unpause_timer'], true) && $integer > intdiv(NetworkParams::MAXIMUM_TICK, NetworkParams::TICKS_PER_SECOND)) {
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
    public function loadFile(string $path, bool $worldOnly = false, array $including = []): void
    {
        $values = $this->values;
        $worlds = $this->worlds;

        foreach (ConfigFile::read($path, $including, $worldOnly, static::class) as $entry) {
            if ($entry instanceof WorldConfig) {
                if (isset($worlds[$entry->identifier])) {
                    throw new \UnexpectedValueException('World already configured: '.$entry->identifier);
                }

                $worlds[$entry->identifier] = $entry;

                continue;
            }

            $values[$entry['name']] = $entry['value'];
        }

        $this->values = $values;
        $this->worlds = $worlds;
    }
}
