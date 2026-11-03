<?php

use PHPUnit\Framework\Assert;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\ConfigFile;
use TeeFrame\Server\Config\ConfigOption;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\Console;

class ConfigLoadingFactoryServer extends AbstractServerInstance
{
    /** @var list<string> */
    public array $worldAttempts = [];

    public int $bootCalls = 0;

    protected function boot(): void
    {
        $this->bootCalls++;
    }

    public function bootWorlds(): void
    {
        $this->bootConfiguredWorlds();
    }

    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        $this->worldAttempts[] = $config->identifier;
        $map                   = $config->settings->getString('sv_world_map');
        $mode                  = $config->settings->getString('sv_world_game_type');
        $secret                = $this->config->getString('sv_rcon_password');

        if ($map !== 'dm1') {
            throw new RuntimeException('Missing map '.$map.'; password='.$secret);
        }

        if ($mode !== 'dm') {
            throw new InvalidArgumentException('Unsupported mode '.$mode.'; password='.$secret);
        }

        $world = new TestWorldWithController(
            $this->tickHandler,
            new Map(__DIR__.'/../dm1.map'),
            new TestGameController($this->tickHandler),
            $this,
            slotsLimit: $config->slotsLimit,
            config: $config->settings,
            spectatorSlots: $config->spectatorSlots,
        );
        $world->identifier = $config->identifier;

        return $world;
    }
}

/**
 * @param  array<string, string>  $files
 * @param  Closure(string): void  $test
 */
function withConfigLoadingFiles(array $files, Closure $test): void
{
    $directory = sys_get_temp_dir().'/teeframe-config-loading-'.bin2hex(random_bytes(8));
    mkdir($directory);

    try {
        foreach ($files as $name => $contents) {
            file_put_contents($directory.'/'.$name, $contents);
        }

        $test($directory);
    } finally {
        foreach (array_keys($files) as $name) {
            chmod($directory.'/'.$name, 0600);
            unlink($directory.'/'.$name);
        }

        rmdir($directory);
    }
}

/** @return list<string> */
function configLoadingErrors(Closure $load): array
{
    $errors   = [];
    $listener = function (string $line) use (&$errors): void {
        $errors[] = $line;
    };
    Console::listen($listener, Console::LEVEL_ERROR);

    try {
        $load();
    } finally {
        Console::unlisten($listener);
    }

    return $errors;
}

test('config loading skips only invalid settings and continues on the same line and later lines', function (string $invalid) {
    withConfigLoadingFiles([
        'server.cfg' => 'sv_name Prefix; '.$invalid."; sv_world_motd Same-line\nsv_rcon_max_tries 7\n",
    ], function (string $directory): void {
        $config = new Config(['sv_name' => 'Original', 'sv_rcon_password' => 'Original password']);
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getString('sv_name'))->toBe('Prefix');
        expect($config->getString('sv_world_motd'))->toBe('Same-line');
        expect($config->getInt('sv_rcon_max_tries'))->toBe(7);
        expect($config->getString('sv_rcon_password'))->toBe('Original password');
        expect($config->getLoadedFiles())->toBe([$directory.'/server.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load configuration setting', 'server.cfg:1:');
        expect($errors[0])->not->toContain('do-not-print-this', 'Original password', 'Prefix', 'Same-line');
    });
})->with([
    'unknown setting'         => 'unknown_do-not-print-this do-not-print-this',
    'out of range'            => 'sv_port 65536',
    'integer type'            => 'sv_port do-not-print-this',
    'extra integer argument'  => 'sv_port 8303 do-not-print-this',
    'missing integer'         => 'sv_port',
    'extra password argument' => 'sv_rcon_password "do-not-print-this" trailing',
    'invalid UTF-8'           => "sv_rcon_password \"do-not-print-this\xff\"",
    'zero byte'               => "sv_rcon_password \"do-not-print-this\0\"",
    'invalid map'             => 'sv_world_map ../do-not-print-this',
    'excessive duration'      => 'sv_world_pvp_warmup '.PHP_INT_MAX,
]);

test('config loading skips malformed quoted lines without exposing their contents', function () {
    withConfigLoadingFiles([
        'server.cfg' => "sv_name Prefix\nsv_name Skipped; sv_rcon_password \"do-not-print-this\nsv_world_motd Later; sv_rcon_max_tries 9\n",
    ], function (string $directory): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getString('sv_name'))->toBe('Prefix');
        expect($config->has('sv_rcon_password'))->toBeFalse();
        expect($config->getString('sv_world_motd'))->toBe('Later');
        expect($config->getInt('sv_rcon_max_tries'))->toBe(9);
        expect($config->getLoadedFiles())->toBe([$directory.'/server.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load configuration line', 'server.cfg:2:', 'malformed command quoting');
        expect($errors[0])->not->toContain('do-not-print-this', 'Skipped', 'sv_rcon_password');
    });
});

test('config loading skips invalid declarations and applies following commands', function (string $invalid) {
    withConfigLoadingFiles([
        'server.cfg' => 'sv_name Prefix; '.$invalid."; add_vote Valid reload; sv_world_add arena dm1 8 1 dm\nsv_world_motd Later\n",
    ], function (string $directory): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getString('sv_name'))->toBe('Prefix');
        expect($config->getString('sv_world_motd'))->toBe('Later');
        expect($config->getVoteOptions())->toHaveCount(1);
        expect($config->getVoteOptions()[0]->description)->toBe('Valid');
        expect($config->getWorlds())->toHaveCount(1);
        expect($config->getWorlds()[0]->identifier)->toBe('arena');
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load', 'server.cfg:1:');
        expect($errors[0])->not->toContain('do-not-print-this');
    });
})->with([
    'missing include argument' => 'exec',
    'extra include argument'   => 'exec do-not-print-this extra',
    'missing vote arguments'   => 'add_vote',
    'missing vote command'     => 'add_vote do-not-print-this',
    'blank vote description'   => 'add_vote "" "echo do-not-print-this"',
    'long vote description'    => 'add_vote "do-not-print-this'.str_repeat('x', 64).'" reload',
    'missing world arguments'  => 'sv_world_add do-not-print-this',
    'invalid client limit'     => 'sv_world_add do-not-print-this dm1 0 0 dm',
    'invalid spectator slots'  => 'sv_world_add do-not-print-this dm1 4 5 dm',
    'invalid map'              => 'sv_world_add invalid ../do-not-print-this 4 0 dm',
    'blank world identifier'   => 'sv_world_add " " dm1 4 0 do-not-print-this',
]);

test('config loading preserves BOM CRLF quoting and depth-first include order around bad includes', function () {
    withConfigLoadingFiles([
        'server.cfg' => "\xEF\xBB\xBFsv_name Before; exec extra.cfg; sv_name \"After; # quoted\" # comment\r\nexec extra.cfg\r\nsv_world_motd \"A \\\"quote\\\" and \\\\ path\"\r\n",
        'extra.cfg'  => "sv_name Included; exec nested.cfg; sv_name Included-after\r\n",
        'nested.cfg' => "exec missing.cfg; sv_name Nested\r\n",
    ], function (string $directory): void {
        $loaded = [];
        $errors = configLoadingErrors(function () use ($directory, &$loaded): void {
            $entries = ConfigFile::read($directory.'/./server.cfg', loadedFiles: $loaded);
            expect($entries)->toBe([
                ['name' => 'sv_name', 'value' => 'Before'],
                ['name' => 'sv_name', 'value' => 'Included'],
                ['name' => 'sv_name', 'value' => 'Nested'],
                ['name' => 'sv_name', 'value' => 'Included-after'],
                ['name' => 'sv_name', 'value' => 'After; # quoted'],
                ['name' => 'sv_name', 'value' => 'Included'],
                ['name' => 'sv_name', 'value' => 'Nested'],
                ['name' => 'sv_name', 'value' => 'Included-after'],
                ['name' => 'sv_world_motd', 'value' => 'A "quote" and \\ path'],
            ]);
        });

        expect($loaded)->toBe(array_map(fn (string $name): string => $directory.'/'.$name, [
            'server.cfg', 'extra.cfg', 'nested.cfg', 'extra.cfg', 'nested.cfg',
        ]));
        expect($errors)->toHaveCount(2);
        foreach ($errors as $error) {
            expect($error)->toContain('[ERROR] [config]: Cannot load configuration file "'.$directory.'/missing.cfg" at '.$directory.'/nested.cfg:1:', 'missing or unreadable');
        }
    });
});

test('config loading skips recursive includes and keeps valid commands in both files', function () {
    withConfigLoadingFiles([
        'server.cfg' => "sv_name Prefix; exec extra.cfg; sv_world_motd After\n",
        'extra.cfg'  => "sv_rcon_max_tries 7; exec ./server.cfg; sv_world_vote_delay 12\n",
    ], function (string $directory): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getString('sv_name'))->toBe('Prefix');
        expect($config->getString('sv_world_motd'))->toBe('After');
        expect($config->getInt('sv_rcon_max_tries'))->toBe(7);
        expect($config->getInt('sv_world_vote_delay'))->toBe(12);
        expect($config->getLoadedFiles())->toBe([$directory.'/server.cfg', $directory.'/extra.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load configuration include "'.$directory.'/server.cfg" at '.$directory.'/extra.cfg:1:', 'recursive configuration include');
    });
});

test('config loading returns no entries and records no loaded file for missing roots', function () {
    withConfigLoadingFiles([], function (string $directory): void {
        $loaded = ['already-loaded.cfg'];
        $errors = configLoadingErrors(function () use ($directory, &$loaded): void {
            expect(ConfigFile::read($directory.'/missing.cfg', loadedFiles: $loaded))->toBeEmpty();
        });

        expect($loaded)->toBe(['already-loaded.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toBe('[ERROR] [config]: Cannot load configuration file "'.$directory.'/missing.cfg": file is missing or unreadable');

        $config = new Config(['sv_name' => 'Original']);
        configLoadingErrors(fn () => $config->loadFile($directory.'/missing.cfg'));
        expect($config->getString('sv_name'))->toBe('Original');
        expect($config->getLoadedFiles())->toBeEmpty();
    });
});

test('missing startup config files have no synthetic source location', function () {
    withConfigLoadingFiles([], function (string $directory): void {
        $errors = configLoadingErrors(fn () => new ConfigLoadingFactoryServer(new TickHandler, $directory.'/server.cfg'));

        expect($errors)->toBe(['[ERROR] [config]: Cannot load configuration file "'.$directory.'/server.cfg": file is missing or unreadable']);
    });
});

test('self includes retain the real source location even when source and target paths match', function () {
    withConfigLoadingFiles(['server.cfg' => "\nexec server.cfg\nsv_name Retained"], function (string $directory): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($errors)->toBe(['[ERROR] [config]: Cannot load configuration include "'.$directory.'/server.cfg" at '.$directory.'/server.cfg:2: recursive configuration include']);
        expect($config->getString('sv_name'))->toBe('Retained');
    });
});

test('config loading skips unreadable includes without recording them', function () {
    withConfigLoadingFiles([
        'server.cfg'     => "sv_name Prefix; exec unreadable.cfg; sv_world_motd After\n",
        'unreadable.cfg' => 'sv_name Unreadable',
    ], function (string $directory): void {
        chmod($directory.'/unreadable.cfg', 0000);
        clearstatcache();
        if (is_readable($directory.'/unreadable.cfg')) {
            Assert::markTestSkipped('The current user can read files without read permissions');
        }

        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getString('sv_name'))->toBe('Prefix');
        expect($config->getString('sv_world_motd'))->toBe('After');
        expect($config->getLoadedFiles())->toBe([$directory.'/server.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load configuration file "'.$directory.'/unreadable.cfg" at '.$directory.'/server.cfg:1:', 'missing or unreadable');

        $loaded = [];
        $errors = configLoadingErrors(function () use ($directory, &$loaded): void {
            expect(ConfigFile::read($directory.'/unreadable.cfg', loadedFiles: $loaded))->toBeEmpty();
        });
        expect($loaded)->toBeEmpty();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toBe('[ERROR] [config]: Cannot load configuration file "'.$directory.'/unreadable.cfg": file is missing or unreadable');
    });
});

test('config loading keeps world scope restrictions through nested includes and resumes after violations', function () {
    withConfigLoadingFiles([
        'world.cfg'  => "sv_world_motd Prefix; exec nested.cfg; sv_world_vote_delay 8\n",
        'nested.cfg' => "sv_name do-not-print-this; sv_world_add forbidden dm1 4 0 dm; sv_world_welcome Welcome\n",
    ], function (string $directory): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/world.cfg', worldOnly: true));

        expect($config->getString('sv_world_motd'))->toBe('Prefix');
        expect($config->getString('sv_world_welcome'))->toBe('Welcome');
        expect($config->getInt('sv_world_vote_delay'))->toBe(8);
        expect($config->has('sv_name'))->toBeFalse();
        expect($config->getWorlds())->toBeEmpty();
        expect($errors)->toHaveCount(2);
        expect($errors[0])->toContain('nested.cfg:1:', 'server settings cannot be changed in a world config');
        expect($errors[1])->toContain('nested.cfg:1:', 'world configs cannot add other worlds');
        expect(implode("\n", $errors))->not->toContain('do-not-print-this', 'forbidden');
    });
});

test('config loading passes the supplied instance schema to includes and world settings', function () {
    withConfigLoadingFiles([
        'server.cfg'   => 'app_limit 8; exec included.cfg; sv_world_add arena dm1 8 0 dm world.cfg; app_limit 9',
        'included.cfg' => 'app_limit 101; app_message Shared; exec nested.cfg',
        'nested.cfg'   => 'app_limit 6',
        'world.cfg'    => 'exec messages.cfg',
        'messages.cfg' => 'app_limit 5; app_message Local',
    ], function (string $directory): void {
        $config = new Config;
        $config->registerOption(new ConfigOption('app_limit', 1, 'Custom server limit', 'server', min: 0, max: 100));
        $config->registerOption(new ConfigOption('app_message', '', 'Custom world message', bytes: 63));
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg'));

        expect($config->getInt('app_limit'))->toBe(9);
        expect($config->getString('app_message'))->toBe('Shared');
        expect($config->getWorlds())->toHaveCount(1);
        $world = $config->getWorlds()[0];
        expect($world->settings->getString('app_message'))->toBe('Local');
        expect($world->settings->has('app_limit'))->toBeFalse();
        expect($world->settings->getLoadedFiles())->toBe([$directory.'/world.cfg', $directory.'/messages.cfg']);
        expect($config->getLoadedFiles())->toBe(array_map(fn (string $name): string => $directory.'/'.$name, [
            'server.cfg', 'included.cfg', 'nested.cfg', 'world.cfg', 'messages.cfg',
        ]));
        expect($errors)->toHaveCount(2);
        expect($errors[0])->toContain('included.cfg:1:', 'value fails type, range or format validation');
        expect($errors[1])->toContain('messages.cfg:1:', 'server settings cannot be changed in a world config');
    });
});

test('config loading propagates PvP availability through root includes and world files without losing later settings', function (bool $activated) {
    withConfigLoadingFiles([
        'server.cfg'      => "sv_name Prefix; SV_WORLD_PVP_SCORE_LIMIT 12; sv_world_motd Root; exec extra.cfg; sv_world_add arena dm1 8 0 dm world.cfg; sv_name Final\nsv_world_welcome Later",
        'extra.cfg'       => 'sv_world_pvp_map_rotation "do-not-print-this"; sv_rcon_max_tries 7; exec nested.cfg',
        'nested.cfg'      => 'sv_world_pvp_team_damage 1; sv_world_vote_duration 30',
        'world.cfg'       => "sv_world_pvp_score_limit 34; sv_world_motd World; exec world-extra.cfg\nsv_world_vote_delay 9",
        'world-extra.cfg' => 'sv_world_pvp_warmup 5; sv_world_welcome World-later',
    ], function (string $directory) use ($activated): void {
        $config = new Config;
        $errors = configLoadingErrors(fn () => $config->loadFile($directory.'/server.cfg', activatePvpSettings: $activated));

        expect($config->getString('sv_name'))->toBe('Final');
        expect($config->getString('sv_world_motd'))->toBe('Root');
        expect($config->getString('sv_world_welcome'))->toBe('Later');
        expect($config->getInt('sv_rcon_max_tries'))->toBe(7);
        expect($config->getInt('sv_world_vote_duration'))->toBe(30);
        expect($config->has('sv_world_pvp_score_limit'))->toBe($activated);
        expect($config->getInt('sv_world_pvp_score_limit'))->toBe($activated ? 12 : 0);
        expect($config->has('sv_world_pvp_map_rotation'))->toBe($activated);
        expect($config->getString('sv_world_pvp_map_rotation'))->toBe($activated ? 'do-not-print-this' : '');
        expect($config->has('sv_world_pvp_team_damage'))->toBe($activated);
        expect($config->getInt('sv_world_pvp_team_damage'))->toBe($activated ? 1 : 0);
        expect($config->getWorlds())->toHaveCount(1);
        $settings = $config->getWorlds()[0]->settings;
        expect($settings->getString('sv_world_motd'))->toBe('World');
        expect($settings->getString('sv_world_welcome'))->toBe('World-later');
        expect($settings->getInt('sv_world_vote_delay'))->toBe(9);
        expect($settings->has('sv_world_pvp_score_limit'))->toBe($activated);
        expect($settings->getInt('sv_world_pvp_score_limit'))->toBe($activated ? 34 : 0);
        expect($settings->has('sv_world_pvp_warmup'))->toBe($activated);
        expect($settings->getInt('sv_world_pvp_warmup'))->toBe($activated ? 5 : 0);
        expect($config->getLoadedFiles())->toBe(array_map(fn (string $name): string => $directory.'/'.$name, [
            'server.cfg', 'extra.cfg', 'nested.cfg', 'world.cfg', 'world-extra.cfg',
        ]));
        expect($settings->getLoadedFiles())->toBe([$directory.'/world.cfg', $directory.'/world-extra.cfg']);
        expect($errors)->toHaveCount($activated ? 0 : 5);
        foreach ($errors as $index => $error) {
            expect($error)->toContain('[ERROR] [config]: Cannot load configuration setting', 'unknown or unavailable configuration setting');
            expect($error)->toContain(['server.cfg:1:', 'extra.cfg:1:', 'nested.cfg:1:', 'world.cfg:1:', 'world-extra.cfg:1:'][$index]);
            expect($error)->not->toContain('do-not-print-this', 'sv_world_pvp_', 'Prefix', 'World-later');
        }
    });
})->with([false, true]);

test('direct config file reads filter unavailable PvP settings without mutating the schema', function () {
    withConfigLoadingFiles([
        'server.cfg' => 'sv_world_pvp_score_limit 12; sv_name Applied',
    ], function (string $directory): void {
        $schema = new Config;
        $loaded = [];
        $errors = configLoadingErrors(function () use ($directory, $schema, &$loaded): void {
            expect(ConfigFile::read($directory.'/server.cfg', loadedFiles: $loaded, config: $schema, activatePvpSettings: false))->toBe([
                ['name' => 'sv_name', 'value' => 'Applied'],
            ]);
        });
        expect($loaded)->toBe([$directory.'/server.cfg']);
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('[ERROR] [config]: Cannot load', 'server.cfg:1:', 'unknown or unavailable configuration setting');
        expect(ConfigFile::read($directory.'/server.cfg', config: $schema))->toBe([
            ['name' => 'sv_world_pvp_score_limit', 'value' => 12],
            ['name' => 'sv_name', 'value' => 'Applied'],
        ]);
        expect($schema->has('sv_world_pvp_score_limit'))->toBeFalse();
    });
});

test('config loading does not leak custom validation exceptions containing secret values', function () {
    withConfigLoadingFiles([
        'server.cfg' => 'sv_rcon_password do-not-print-this; sv_name Applied',
    ], function (string $directory): void {
        $schema = new class extends Config
        {
            public function validateValue(string $name, #[SensitiveParameter] int|string $value): int|string
            {
                if ($name === 'sv_rcon_password') {
                    throw new InvalidArgumentException('Invalid secret: '.$value);
                }

                return parent::validateValue($name, $value);
            }
        };
        $errors = configLoadingErrors(function () use ($directory, $schema): void {
            expect(ConfigFile::read($directory.'/server.cfg', config: $schema))->toBe([
                ['name' => 'sv_name', 'value' => 'Applied'],
            ]);
        });

        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('server.cfg:1:', 'value fails type, range or format validation');
        expect($errors[0])->not->toContain('do-not-print-this', 'Invalid secret');
    });
});

test('configured world factory failures skip invalid maps or modes and boot valid neighbors', function (string $map, string $mode) {
    withConfigLoadingFiles([
        'server.cfg' => "sv_rcon_password do-not-print-this\nsv_world_add before dm1 8 0 dm\nsv_world_add broken ".$map.' 8 0 '.$mode."\nsv_world_add after dm1 12 2 dm\nsv_name Applied",
    ], function (string $directory) use ($map, $mode): void {
        $server = new ConfigLoadingFactoryServer(new TickHandler, $directory.'/server.cfg', activatePvpSettings: true);
        expect($server->getConfig()->getWorlds())->toHaveCount(3);
        expect($server->getWorlds())->toBeEmpty();
        $errors = configLoadingErrors(fn () => $server->bootWorlds());

        expect($server->worldAttempts)->toBe(['before', 'broken', 'after']);
        expect(array_map(fn (AbstractWorld $world): string => $world->identifier, $server->getWorlds()))->toBe(['before', 'after']);
        expect($server->getWorlds()[0]->getSlotsLimit())->toBe(8);
        expect($server->getWorlds()[1]->getSlotsLimit())->toBe(12);
        expect($server->getWorlds()[1]->getSpectatorSlots())->toBe(2);
        expect($server->getName())->toBe('Applied');
        expect($server->bootCalls)->toBe(0);
        expect($server->isStarted())->toBeFalse();
        expect($errors)->toBe(['[ERROR] [config]: Cannot load configured world broken: world creation failed']);
        expect(implode("\n", $errors))->not->toContain('do-not-print-this', $map === 'dm1' ? $mode : $map, 'password=');
    });
})->with([
    'missing map'      => ['missing-do-not-print-this', 'dm'],
    'unsupported mode' => ['dm1', 'unsupported-do-not-print-this'],
]);

test('startup without valid worlds logs and returns without starting resources or creating a fallback', function (string $contents, array $attempts) {
    withConfigLoadingFiles(['server.cfg' => $contents], function (string $directory) use ($attempts): void {
        $server = new ConfigLoadingFactoryServer(new TickHandler, $directory.'/server.cfg');
        $errors = configLoadingErrors(fn () => $server->start());

        expect($server->bootCalls)->toBe(1);
        expect($server->worldAttempts)->toBe($attempts);
        expect($server->getWorlds())->toBeEmpty();
        expect($server->isStarted())->toBeFalse();
        expect($errors)->toHaveCount(count($attempts) + 1);
        if ($attempts !== []) {
            expect($errors[0])->toBe('[ERROR] [config]: Cannot load configured world broken: world creation failed');
        }
        expect(end($errors))->toBe('[ERROR] [server]: Cannot start server: no valid worlds were loaded');
    });
})->with([
    'no declarations'   => ['', []],
    'no loadable map'   => ['sv_world_add broken missing 8 0 dm', ['broken']],
    'no supported mode' => ['sv_world_add broken dm1 8 0 unsupported', ['broken']],
]);

test('startup skips an unusable log file path without exposing its value', function () {
    withConfigLoadingFiles(['server.cfg' => ''], function (string $directory): void {
        $logFile = $directory.'/do-not-print-this/server.log';
        file_put_contents($directory.'/server.cfg', 'sv_name Applied; sv_log_file "'.$logFile."\"\nsv_world_motd After");
        ob_start();
        try {
            $errors = configLoadingErrors(function () use ($directory): void {
                $server = new ConfigLoadingFactoryServer(new TickHandler, $directory.'/server.cfg');
                expect($server->getConfig()->getString('sv_log_file'))->toBe('');
                expect($server->getName())->toBe('Applied');
                expect($server->getConfigValue('sv_world_motd'))->toBe('After');
                expect($server->getConfig()->getLoadedFiles())->toBe([$directory.'/server.cfg']);
                expect($server->bootCalls)->toBe(0);
                expect($server->isStarted())->toBeFalse();
            });
            expect($errors)->toBe(['[ERROR] [config]: Cannot load configuration setting sv_log_file: cannot open log file']);
            expect(implode("\n", $errors))->not->toContain($logFile, 'do-not-print-this');
            expect((string) ob_get_contents())->not->toContain($logFile, 'do-not-print-this');
            expect(file_exists($logFile))->toBeFalse();
        } finally {
            ob_end_clean();
            Console::setLogFile(null);
        }
    });
});

test('config loading does not swallow programming errors from a schema', function () {
    withConfigLoadingFiles(['server.cfg' => 'sv_name Applied'], function (string $directory): void {
        $schema = new class extends Config
        {
            public function validateValue(string $name, #[SensitiveParameter] int|string $value): int|string
            {
                throw new LogicException('Broken validator');
            }
        };

        expect(fn () => ConfigFile::read($directory.'/server.cfg', config: $schema))->toThrow(LogicException::class, 'Broken validator');
    });
});
