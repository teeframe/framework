<?php

use PHPUnit\Framework\Assert;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\Server\ConfigCommand;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdAddChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\ConfigOption;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\Console;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

class RegistrationLegacyConfig extends Config
{
    protected const DEFINITIONS = [
        'sv_world_pvp_score_limit' => ['default' => 12, 'min' => 0, 'max' => 1000, 'scope' => 'world', 'description' => 'Legacy score limit'],
        'legacy_option'            => ['default' => 3, 'min' => 0, 'max' => 100, 'scope' => 'world', 'description' => 'Legacy option'],
    ] + parent::DEFINITIONS;
}

class RegistrationBootConfigServer extends AbstractServerInstance
{
    protected function bootConfig(): void
    {
        $this->registerConfigOption(new ConfigOption('app_boot_server', 1, 'Boot server setting', scope: 'server', min: 0, max: 20));
        $this->registerConfigOption(new ConfigOption('app_boot_world', 2, 'Boot world setting', min: 0, max: 20));
    }

    public function bootWorlds(): void
    {
        $this->bootConfiguredWorlds();
    }

    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        $world = new TestWorldWithController(
            $this->tickHandler,
            new Map(__DIR__.'/../'.$config->settings->getString('sv_world_map').'.map'),
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
function withRegistrationConfigFiles(array $files, Closure $test): void
{
    $directory = sys_get_temp_dir().'/teeframe-registration-'.bin2hex(random_bytes(8));
    mkdir($directory);

    try {
        foreach ($files as $name => $contents) {
            file_put_contents($directory.'/'.$name, $contents);
        }

        $test($directory);
    } finally {
        foreach (array_keys($files) as $name) {
            unlink($directory.'/'.$name);
        }

        rmdir($directory);
    }
}

function restartRegistrationConfig(): Config
{
    $config = new Config(['sv_rcon_password' => 'test-secret']);
    $config->registerOption(new ConfigOption('sv_registration_restart', 1, 'Restart-only server setting', scope: 'server', min: 0, max: 30, restart: true));
    $config->registerOption(new ConfigOption('sv_world_registration_restart', 2, 'Restart-only world setting', min: 0, max: 30, restart: true));
    $config->registerOption(new ConfigOption('sv_world_registration_live', 0, 'Live world setting', min: 0, max: 30));

    return $config;
}

test('registered configuration options expose immutable metadata', function () {
    $option = new ConfigOption('app_limit', 5, 'Application limit', scope: 'server', min: 1, max: 10, restart: true, secret: true);

    expect((new ReflectionClass($option))->isFinal())->toBeTrue();
    expect((new ReflectionClass($option))->isReadOnly())->toBeTrue();
    expect($option->getDefinition())->toBe([
        'default'     => 5,
        'description' => 'Application limit',
        'scope'       => 'server',
        'restart'     => true,
        'secret'      => true,
        'min'         => 1,
        'max'         => 10,
    ]);
    expect((new ConfigOption('app_label', '', 'Application label'))->getDefinition()['scope'])->toBe('world');
});

test('registration and explicit values are isolated between configuration instances and static APIs', function () {
    $first  = new Config;
    $second = new Config;
    $first->registerOption(new ConfigOption('APP_LIMIT', 5, 'First limit', min: 0, max: 10));
    $second->registerOption(new ConfigOption('app_limit', 8, 'Second limit', min: 0, max: 20));

    expect($first->getInt('app_limit'))->toBe(5);
    expect($first->has('app_limit'))->toBeFalse();
    expect($second->getDefinition('APP_LIMIT')['description'])->toBe('Second limit');
    expect($first->validateValue('APP_LIMIT', '9'))->toBe(9);
    $first->set('app_limit', '9');
    expect($first->getInt('APP_LIMIT'))->toBe(9);
    expect($second->getInt('app_limit'))->toBe(8);
    expect(fn () => $first->set('app_limit', 11))->toThrow(InvalidArgumentException::class);
    expect(fn () => $first->set('app_limit', 'invalid'))->toThrow(InvalidArgumentException::class);
    expect($first->getInt('app_limit'))->toBe(9);
    expect(fn () => (new Config)->getDefinition('app_limit'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Config::definition('app_limit'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Config::validate('app_limit', 1))->toThrow(InvalidArgumentException::class);
    expect(Config::definitions())->not->toHaveKey('app_limit');
    expect(Config::validate('sv_port', '8304'))->toBe(8304);
});

test('registration rejects duplicate options and replacement of built-in or legacy definitions', function () {
    $config = new RegistrationLegacyConfig;
    $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));

    foreach (['APP_LIMIT', 'sv_name', 'legacy_option'] as $name) {
        expect(fn () => $config->registerOption(new ConfigOption($name, 0, 'Replacement')))->toThrow(LogicException::class);
    }

    expect($config->getInt('app_limit'))->toBe(5);
    expect($config->getInt('legacy_option'))->toBe(3);
    expect($config->getString('sv_name'))->toBe('TeeFrame');
    expect(fn () => $config->createLayer()->withParent($config)->registerOption(new ConfigOption('app_limit', 0, 'Inherited replacement')))->toThrow(LogicException::class);
});

test('invalid option metadata and defaults are rejected without installing the option', function (ConfigOption $option) {
    $config = new Config;

    expect(fn () => $config->registerOption($option))->toThrow(InvalidArgumentException::class);
    expect($config->getDefinitions())->not->toHaveKey(strtolower($option->name));
})->with([
    'blank name'               => new ConfigOption('', 0, 'Option'),
    'command separator'        => new ConfigOption('app_limit;quit', 0, 'Option'),
    'non-ASCII name'           => new ConfigOption('app_é', 0, 'Option'),
    'blank description'        => new ConfigOption('app_option', 0, '  '),
    'invalid description UTF8' => new ConfigOption('app_option', 0, "\xff"),
    'zero in description'      => new ConfigOption('app_option', 0, "Option\0"),
    'inverted integer range'   => new ConfigOption('app_option', 0, 'Option', min: 2, max: 1),
    'negative implicit range'  => new ConfigOption('app_option', 0, 'Option', max: -1),
    'default below minimum'    => new ConfigOption('app_option', 1, 'Option', min: 2),
    'default above maximum'    => new ConfigOption('app_option', 3, 'Option', max: 2),
    'integer byte limit'       => new ConfigOption('app_option', 0, 'Option', bytes: 10),
    'string minimum'           => new ConfigOption('app_option', '', 'Option', min: 0),
    'string maximum'           => new ConfigOption('app_option', '', 'Option', max: 10),
    'negative byte limit'      => new ConfigOption('app_option', '', 'Option', bytes: -1),
    'oversized default'        => new ConfigOption('app_option', 'éé', 'Option', bytes: 3),
    'invalid default UTF8'     => new ConfigOption('app_option', "\xff", 'Option'),
    'zero in default'          => new ConfigOption('app_option', "private\0default", 'Option', secret: true),
]);

test('registration validates scope at runtime', function () {
    $option = (new ReflectionClass(ConfigOption::class))->newInstanceArgs(['app_option', 0, 'Option', 'invalid']);

    expect(fn () => (new Config)->registerOption($option))->toThrow(InvalidArgumentException::class);
});

test('registered string values retain exact typing UTF8 truncation and secret-safe errors', function () {
    $config = new Config;
    $config->registerOption(new ConfigOption('app_secret', '', 'Private label', bytes: 5, secret: true));
    $config->registerOption(new ConfigOption('app_empty', '', 'Empty label', bytes: 0));
    $config->registerOption(new ConfigOption('app_signed', -3, 'Signed number', min: -5, max: -1));

    expect($config->validateValue('app_secret', 'ééé'))->toBe('éé');
    expect($config->validateValue('app_empty', 'anything'))->toBe('');
    expect($config->getInt('app_signed'))->toBe(-3);
    expect($config->validateValue('app_signed', '-5'))->toBe(-5);
    expect(fn () => $config->validateValue('app_secret', 123))->toThrow(InvalidArgumentException::class);

    foreach (["private\0value", "private\xffvalue"] as $value) {
        try {
            $config->set('app_secret', $value);
            Assert::fail('Invalid secret was accepted');
        } catch (InvalidArgumentException $exception) {
            expect($exception->getMessage())->not->toContain('private');
        }
    }
});

test('registrations and legacy subclass defaults survive parent attachment and nested world layers', function () {
    $parent = new RegistrationLegacyConfig;
    $parent->registerOption(new ConfigOption('app_limit', 5, 'Application limit', min: 0, max: 20));
    $parent->set('app_limit', 7);
    $parent->set('legacy_option', 9);

    $local = new Config(['sv_world_welcome' => 'Local welcome']);
    $local->registerOption(new ConfigOption('local_label', 'local', 'Local label'));
    $child  = $local->withParent($parent);
    $nested = $child->createLayer()->withParent($child);

    foreach ([$child, $nested, new Config(parent: $parent)] as $layer) {
        expect($layer->getInt('app_limit'))->toBe(7);
        expect($layer->getInt('legacy_option'))->toBe(9);
    }

    $nested->set('app_limit', 10);
    expect($nested->getInt('app_limit'))->toBe(10);
    expect($child->getInt('app_limit'))->toBe(7);

    expect($child)->toBeInstanceOf(RegistrationLegacyConfig::class);
    expect($nested)->toBeInstanceOf(RegistrationLegacyConfig::class);
    expect($child->getString('local_label'))->toBe('local');
    expect($nested->getString('local_label'))->toBe('local');
    expect($nested->getString('sv_world_welcome'))->toBe('Local welcome');
    expect($child->getInt('sv_world_pvp_score_limit'))->toBe(12);
    expect($parent->getInt('app_limit'))->toBe(7);
    expect(fn () => $parent->get('local_label'))->toThrow(InvalidArgumentException::class);

    $specialized = (new RegistrationLegacyConfig)->withParent(new Config);
    expect($specialized->getInt('sv_world_pvp_score_limit'))->toBe(12);
    expect($specialized->getInt('legacy_option'))->toBe(3);
});

test('reparenting preserves registrations inherited through constructor parents without copying their values', function () {
    $original = new Config;
    $original->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));
    $original->set('app_limit', 9);
    $child      = new Config(parent: new Config(parent: $original));
    $reattached = $child->withParent(new Config);

    expect($child->getInt('app_limit'))->toBe(9);
    expect($reattached->getInt('app_limit'))->toBe(5);
    $reattached->set('app_limit', 12);
    expect($reattached->getInt('app_limit'))->toBe(12);
    expect($original->getInt('app_limit'))->toBe(9);
});

test('createLayer copies the schema but not explicit values parents worlds votes or loaded files', function () {
    withRegistrationConfigFiles([
        'server.cfg' => "app_limit 9\nsv_world_add arena dm1 8 0 dm\nadd_vote Reload reload",
    ], function (string $directory): void {
        $config = new RegistrationLegacyConfig(parent: new Config(['sv_name' => 'Ancestor']));
        $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));
        $config->loadFile($directory.'/server.cfg');
        $layer = $config->createLayer();

        expect($config->getWorlds())->toHaveCount(1);
        expect($config->getVoteOptions())->toHaveCount(1);
        expect($config->getLoadedFiles())->toHaveCount(1);
        expect($layer)->toBeInstanceOf(RegistrationLegacyConfig::class);
        expect($layer->getDefinitions())->toBe($config->getDefinitions());
        expect($layer->getInt('app_limit'))->toBe(5);
        expect($layer->getInt('sv_world_pvp_score_limit'))->toBe(12);
        expect($layer->getString('sv_name'))->toBe('TeeFrame');
        expect($layer->has('app_limit'))->toBeFalse();
        expect($layer->getWorlds())->toBeEmpty();
        expect($layer->getVoteOptions())->toBeEmpty();
        expect($layer->getLoadedFiles())->toBeEmpty();
        $layer->registerOption(new ConfigOption('layer_only', 1, 'Local option'));
        expect($config->getDefinitions())->not->toHaveKey('layer_only');
    });
});

test('instance file loading preserves custom zero-argument configuration initialization', function () {
    $config = new class extends RegistrationLegacyConfig
    {
        public bool $initialized = false;

        public function __construct()
        {
            parent::__construct(['sv_name' => 'Constructed']);
            $this->registerOption(new ConfigOption('constructor_option', 1, 'Constructor option'));
            $this->initialized = true;
        }
    };

    withRegistrationConfigFiles([
        'server.cfg' => "constructor_option 9\nlegacy_option 8",
    ], function (string $directory) use ($config): void {
        $config->loadFile($directory.'/server.cfg');

        expect($config)->toBeInstanceOf(RegistrationLegacyConfig::class);
        expect($config->initialized)->toBeTrue();
        expect($config->getString('sv_name'))->toBe('Constructed');
        expect($config->getInt('constructor_option'))->toBe(9);
        expect($config->getInt('legacy_option'))->toBe(8);
    });
});

test('file factories return the base config regardless of subclass constructors', function () {
    $config = new class('Custom config') extends RegistrationLegacyConfig
    {
        public function __construct(public string $label)
        {
            parent::__construct(['sv_name' => $label]);
        }
    };

    withRegistrationConfigFiles(['server.cfg' => 'sv_world_chat_delay 2'], function (string $directory) use ($config): void {
        $loaded = $config::fromFile($directory.'/server.cfg');

        expect($loaded::class)->toBe(Config::class);
        expect($loaded->getInt('sv_world_chat_delay'))->toBe(2);
        expect($loaded->getString('sv_name'))->toBe('TeeFrame');

        $config->loadFile($directory.'/server.cfg');

        expect($config->getInt('sv_world_chat_delay'))->toBe(2);
        expect($config->getString('sv_name'))->toBe('Custom config');
        expect($config->label)->toBe('Custom config');
    });
});

test('WorldConfig accepts registered schemas without copying server values and retains its legacy constructor', function () {
    $config = new Config;
    $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));
    $config->set('app_limit', 9);
    $first  = new WorldConfig('arena', 'dm1', 8, 2, 'ctf', config: $config);
    $second = WorldConfig::fromArguments(['other', 'dm2', '16', '0'], config: $config);
    $legacy = new WorldConfig('legacy', 'dm1', 8, 0, configClass: RegistrationLegacyConfig::class);

    expect($first->settings->getInt('app_limit'))->toBe(5);
    expect($first->settings->getString('sv_world_map'))->toBe('dm1');
    expect($first->settings->getString('sv_world_game_type'))->toBe('ctf');
    expect($second->settings->getString('sv_world_map'))->toBe('dm2');
    expect($legacy->settings)->toBeInstanceOf(RegistrationLegacyConfig::class);
    expect($legacy->settings->getInt('legacy_option'))->toBe(3);
    $first->settings->set('app_limit', 11);
    expect($second->settings->getInt('app_limit'))->toBe(5);
    expect($config->getInt('app_limit'))->toBe(9);
    expect($first->settings->withParent($config)->getInt('app_limit'))->toBe(11);
    expect($second->settings->withParent($config)->getInt('app_limit'))->toBe(9);

    $requiredConstructor = new class([]) extends RegistrationLegacyConfig
    {
        /** @param array<string, int|string> $values */
        public function __construct(array $values)
        {
            parent::__construct($values);
        }
    };
    $legacyArguments = WorldConfig::fromArguments(['legacy-arguments', 'dm2', '8', '0'], $requiredConstructor::class);
    expect($legacyArguments->settings->getString('sv_world_map'))->toBe('dm2');
    expect($legacyArguments->settings->getInt('legacy_option'))->toBe(3);
});

test('registered schemas load custom server and world settings through nested includes', function () {
    withRegistrationConfigFiles([
        'server.cfg' => "app_limit 7\nexec shared.cfg\nsv_world_add arena dm1 8 2 dm world.cfg\nsv_world_add other dm1 8 0 dm",
        'shared.cfg' => 'app_label "Server label"',
        'world.cfg'  => 'exec nested.cfg',
        'nested.cfg' => 'app_limit 16',
    ], function (string $directory): void {
        $config = new Config;
        $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit', max: 20));
        $config->registerOption(new ConfigOption('app_label', '', 'Server label', scope: 'server', bytes: 63));
        $config->loadFile($directory.'/server.cfg');
        [$first, $second] = $config->getWorlds();

        expect($config->getInt('app_limit'))->toBe(7);
        expect($config->getString('app_label'))->toBe('Server label');
        expect($first->settings->withParent($config)->getInt('app_limit'))->toBe(16);
        expect($second->settings->withParent($config)->getInt('app_limit'))->toBe(7);
        expect($config->getLoadedFiles())->toBe([
            $directory.'/server.cfg', $directory.'/shared.cfg', $directory.'/world.cfg', $directory.'/nested.cfg',
        ]);
        expect($first->settings->getLoadedFiles())->toBe([$directory.'/world.cfg', $directory.'/nested.cfg']);
        expect((new Config)->getDefinitions())->not->toHaveKey('app_limit');
    });
});

test('world-only includes enforce the scope of registered options and retain following valid settings', function () {
    withRegistrationConfigFiles([
        'world.cfg'  => "exec nested.cfg\napp_limit 11",
        'nested.cfg' => 'app_secret private-value',
    ], function (string $directory): void {
        $config = new Config;
        $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));
        $config->registerOption(new ConfigOption('app_secret', '', 'Server secret', scope: 'server', secret: true));
        $world    = new WorldConfig('arena', 'dm1', 8, 0, config: $config);
        $lines    = [];
        $listener = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        Console::listen($listener);

        try {
            $world->settings->loadFile($directory.'/world.cfg', worldOnly: true);
        } finally {
            Console::unlisten($listener);
        }

        expect($world->settings->getInt('app_limit'))->toBe(11);
        expect($world->settings->has('app_secret'))->toBeFalse();
        expect($config->getString('app_secret'))->toBe('');
        expect(implode("\n", $lines))->toContain('server settings cannot be changed in a world config');
        expect(implode("\n", $lines))->not->toContain('private-value');
    });
});

test('duplicate world declarations are nonfatal preserve earlier data and never log configuration values', function () {
    withRegistrationConfigFiles([
        'server.cfg' => "sv_world_welcome before\nsv_world_add private-identifier dm1 8 0 dm\nsv_world_add private-identifier dm2 16 2 ctf\nsv_world_add later dm1 4 1 dm\nsv_world_welcome private-after",
        'later.cfg'  => "sv_world_add private-identifier dm2 16 0 dm\nsv_world_add last dm1 4 0 dm",
    ], function (string $directory): void {
        $lines    = [];
        $listener = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        Console::listen($listener);

        try {
            $config = Config::fromFile($directory.'/server.cfg');
            $config->loadFile($directory.'/later.cfg');
        } finally {
            Console::unlisten($listener);
        }

        expect(array_map(fn (WorldConfig $world): string => $world->identifier, $config->getWorlds()))->toBe(['private-identifier', 'later', 'last']);
        expect($config->getWorlds()[0]->slotsLimit)->toBe(8);
        expect($config->getWorlds()[0]->settings->getString('sv_world_map'))->toBe('dm1');
        expect($config->getString('sv_world_welcome'))->toBe('private-after');
        expect($lines)->toHaveCount(2);
        expect(implode("\n", $lines))->toContain('[ERROR] [config]: Cannot load', 'duplicate world');
        expect(implode("\n", $lines))->not->toContain('private-identifier', 'private-after', 'dm2');
    });
});

test('registered PvP settings use instance metadata for filtering and explicit-value checks', function () {
    $config = new Config;
    $config->registerOption(new ConfigOption('sv_world_pvp_custom', 1, 'Custom PvP option'));
    expect($config->getDefinitions())->toHaveKey('sv_world_pvp_custom');
    expect($config->getDefinitions(false))->not->toHaveKey('sv_world_pvp_custom');
    expect(fn () => $config->getDefinition('SV_WORLD_PVP_CUSTOM', false))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    $config->assertPvpSettingsEnabled(false);
    $layer = $config->createLayer()->withParent($config);
    $layer->set('sv_world_pvp_custom', 2);
    $layer->assertPvpSettingsEnabled(true);
    expect(fn () => $layer->assertPvpSettingsEnabled(false))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
});

test('configuration commands accept instance schemas while preserving legacy class-string construction', function () {
    $config = new Config;
    $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit', restart: true));
    $config->registerOption(new ConfigOption('app_secret', '', 'Private label', secret: true));
    $integer = new ConfigCommand('app_limit', $config);
    $string  = new ConfigCommand('app_secret', $config);
    $legacy  = new ConfigCommand('legacy_option', configClass: RegistrationLegacyConfig::class);

    expect($integer->getType())->toBe(AbstractCommand::TYPE_SERVER);
    expect($integer->getParams())->toBe('?i[value]');
    expect($integer->getDescription())->toBe('Application limit (startup config only)');
    expect($string->getParams())->toBe('?r[value]');
    expect($legacy->getDescription())->toBe('Legacy option');
    expect((new ConfigCommand('sv_name'))->getParams())->toBe('?r[value]');

    $requiredConstructor = new class('Required constructor argument') extends RegistrationLegacyConfig
    {
        public function __construct(string $name)
        {
            parent::__construct(['sv_name' => $name]);
        }
    };
    expect((new ConfigCommand('legacy_option', $requiredConstructor::class))->getDescription())->toBe('Legacy option');
});

test('object-backed configuration commands support RCON metadata secret replies and isolated world values', function () {
    $config = new Config(['sv_rcon_password' => 'test-secret']);
    $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit', min: 0, max: 20));
    $config->registerOption(new ConfigOption('app_secret', '', 'Private label', scope: 'server', bytes: 63, secret: true));
    $server = new RconTestServer(config: $config);
    $other  = $server->addWorld('other');
    $admin  = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets = [];

    $info = $server->getRcon()->getCommandInfo($server->entryWorld);
    expect($info['app_limit']['params'])->toBe('?i[value]');
    expect($info['app_limit']['help'])->toBe('Application limit');
    expect($info['app_secret']['params'])->toBe('?r[value]');
    $server->send($admin, new RconCmdChunk('app_limit 11; app_secret private-value; app_secret'));

    $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
    expect($lines)->toContain('app_limit = 11', 'app_secret = [hidden]');
    expect(implode("\n", $lines))->not->toContain('private-value');
    expect($server->entryWorld->getConfigValue('app_limit'))->toBe(11);
    expect($other->getConfigValue('app_limit'))->toBe(5);
    expect($config->getInt('app_limit'))->toBe(5);
    expect($config->getString('app_secret'))->toBe('private-value');

    $plain = new RconTestServer;
    expect($plain->getRcon()->getCommandInfo($plain->entryWorld))->not->toHaveKey('app_limit');
});

test('bootConfig registers instance settings before startup filepath parsing and nested world includes', function () {
    withRegistrationConfigFiles([
        'server.cfg' => "app_boot_server 7\napp_boot_world 9\nexec shared.cfg\nsv_world_add arena dm1 8 2 dm world.cfg\nsv_world_add other dm1 8 0 dm",
        'shared.cfg' => 'app_boot_server 11',
        'world.cfg'  => 'exec nested.cfg',
        'nested.cfg' => 'app_boot_world 16',
    ], function (string $directory): void {
        $server = new RegistrationBootConfigServer(new TickHandler, $directory.'/server.cfg');
        $server->bootWorlds();
        [$first, $second] = $server->getWorlds();

        expect($server->getConfig()::class)->toBe(Config::class);
        expect($server->getConfigValue('app_boot_server'))->toBe(11);
        expect($server->getConfigValue('app_boot_world'))->toBe(9);
        expect($first->getConfigValue('app_boot_world'))->toBe(16);
        expect($second->getConfigValue('app_boot_world'))->toBe(9);
        expect($first->getConfigValue('app_boot_server'))->toBe(11);
        expect($server->getRcon()->getCommandInfo($first)['app_boot_world'])->toBe(['help' => 'Boot world setting', 'params' => '?i[value]']);
        expect($server->getConfig()->getLoadedFiles())->toBe([
            $directory.'/server.cfg', $directory.'/shared.cfg', $directory.'/world.cfg', $directory.'/nested.cfg',
        ]);

        $independent = new RegistrationBootConfigServer(new TickHandler);
        expect($independent->getConfigValue('app_boot_server'))->toBe(1);
        expect($independent->getConfigValue('app_boot_world'))->toBe(2);
        expect((new RconTestServer)->getConfigDefinitions())->not->toHaveKeys(['app_boot_server', 'app_boot_world']);
        expect(Config::definitions())->not->toHaveKeys(['app_boot_server', 'app_boot_world']);
    });
});

test('loadFile forwards PvP activation through registered schemas and nested world includes', function (bool $activatePvpSettings) {
    withRegistrationConfigFiles([
        'server.cfg' => "sv_world_pvp_custom 7\nexec shared.cfg\nsv_world_add arena dm1 8 0 dm world.cfg\napp_limit 9",
        'shared.cfg' => 'sv_world_pvp_score_limit 11',
        'world.cfg'  => 'exec nested.cfg',
        'nested.cfg' => "sv_world_pvp_custom 16\napp_limit 13",
    ], function (string $directory) use ($activatePvpSettings): void {
        $config = new Config;
        $config->registerOption(new ConfigOption('sv_world_pvp_custom', 1, 'Custom PvP setting'));
        $config->registerOption(new ConfigOption('app_limit', 5, 'Application limit'));
        $config->loadFile($directory.'/server.cfg', activatePvpSettings: $activatePvpSettings);
        $world = $config->getWorlds()[0];

        expect($config->getInt('app_limit'))->toBe(9);
        expect($world->settings->getInt('app_limit'))->toBe(13);
        expect($config->has('sv_world_pvp_custom'))->toBe($activatePvpSettings);
        expect($config->has('sv_world_pvp_score_limit'))->toBe($activatePvpSettings);
        expect($world->settings->has('sv_world_pvp_custom'))->toBe($activatePvpSettings);
        expect($config->getInt('sv_world_pvp_custom'))->toBe($activatePvpSettings ? 7 : 1);
        expect($world->settings->getInt('sv_world_pvp_custom'))->toBe($activatePvpSettings ? 16 : 1);
        $config->assertPvpSettingsEnabled($activatePvpSettings);
    });
})->with([false, true]);

test('instance-scoped settings remain shared or local through live updates and RCON world creation', function (bool $exec) {
    withRegistrationConfigFiles([
        'world.cfg'  => 'exec nested.cfg',
        'nested.cfg' => 'app_world 17',
        'worlds.cfg' => 'sv_world_add added dm1 8 2 dm world.cfg',
    ], function (string $directory) use ($exec): void {
        $config = new Config(['sv_rcon_password' => 'test-secret']);
        $config->registerOption(new ConfigOption('app_server', 1, 'Shared server setting', scope: 'server', min: 0, max: 30));
        $config->registerOption(new ConfigOption('app_world', 3, 'Local world setting', min: 0, max: 30));
        $config->set('app_world', 7);
        $server = new RconTestServer(config: $config);
        $first  = $server->entryWorld;
        $second = $server->addWorld('other');
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $first->setConfig('app_server', 9);
        expect($second->getConfigValue('app_server'))->toBe(9);
        expect($config->getInt('app_server'))->toBe(9);

        $server->send($admin, new RconCmdChunk('app_server 11; app_world 13'));
        $command = $exec ? 'exec "'.$directory.'/worlds.cfg"' : 'sv_world_add added dm1 8 2 dm "'.$directory.'/world.cfg"';
        $server->send($admin, new RconCmdChunk($command));
        expect($server->getWorlds())->toHaveCount(3);
        $added = $server->getWorlds()[2];

        expect($added->identifier)->toBe('added');
        expect($added->getConfigValue('app_server'))->toBe(11);
        expect($added->getConfigValue('app_world'))->toBe(17);
        expect($first->getConfigValue('app_world'))->toBe(13);
        expect($second->getConfigValue('app_world'))->toBe(7);
        expect($config->getInt('app_world'))->toBe(7);
        expect($server->getRcon()->getCommandInfo($added)['app_world'])->toBe(['help' => 'Local world setting', 'params' => '?i[value]']);
        $added->setConfig('app_server', 19);
        $added->setConfig('app_world', 18);
        expect($first->getConfigValue('app_server'))->toBe(19);
        expect($second->getConfigValue('app_server'))->toBe(19);
        expect($config->getInt('app_server'))->toBe(19);
        expect($first->getConfigValue('app_world'))->toBe(13);
        expect($second->getConfigValue('app_world'))->toBe(7);
        expect(fn () => $added->setConfig('app_world', 31))->toThrow(InvalidArgumentException::class);
        expect($added->getConfigValue('app_world'))->toBe(18);
        expect((new RconTestServer)->getConfigDefinitions())->not->toHaveKeys(['app_server', 'app_world']);
    });
})->with([false, true]);

test('post-boot registration reaches existing worlds autocomplete and later worlds without leaking to another server', function () {
    $server = new RconTestServer;
    $first  = $server->entryWorld;
    $second = $server->addWorld('other');
    $plain  = new RconTestServer;
    $admin  = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', true));

    foreach ($first->getCommands() as $_) {
        $server->getRcon()->update();
    }
    $server->socket($admin)->packets = [];
    $server->registerConfigOption(new ConfigOption('app_late_world', 5, 'Late world setting', min: 0, max: 20));
    $server->registerConfigOption(new ConfigOption('app_late_secret', '', 'Late server secret', scope: 'server', bytes: 63, secret: true));
    $server->getRcon()->update();

    $advertised = $server->socket($admin)->chunks(RconCmdAddChunk::class);
    expect(array_map(fn (RconCmdAddChunk $chunk): string => $chunk->name, $advertised))->toBe(['app_late_world', 'app_late_secret']);
    expect($advertised[0]->help)->toBe('Late world setting');
    expect($advertised[0]->params)->toBe('?i[value]');
    expect($advertised[1]->params)->toBe('?r[value]');
    $later = $server->addWorld('later');

    foreach ([$first, $second, $later] as $world) {
        expect($world->getConfigValue('app_late_world'))->toBe(5);
        expect($world->getConfig()->getDefinition('app_late_secret')['secret'] ?? false)->toBeTrue();
        expect($world->findCommand('app_late_world', AbstractCommand::TYPE_SERVER))->toBeInstanceOf(ConfigCommand::class);
        expect($server->getRcon()->getCommandInfo($world)['app_late_secret']['params'])->toBe('?r[value]');
    }

    $server->socket($admin)->packets = [];
    $server->send($admin, new RconCmdChunk('app_late_world 11; app_late_secret private-late; app_late_secret'));
    $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
    expect($lines)->toContain('app_late_world = 11', 'app_late_secret = [hidden]');
    expect(implode("\n", $lines))->not->toContain('private-late');
    expect($first->getConfigValue('app_late_world'))->toBe(11);
    expect($second->getConfigValue('app_late_world'))->toBe(5);
    expect($later->getConfigValue('app_late_world'))->toBe(5);
    expect($later->getConfigValue('app_late_secret'))->toBe('private-late');
    expect(fn () => $server->registerConfigOption(new ConfigOption('APP_LATE_WORLD', 1, 'Duplicate')))->toThrow(LogicException::class);
    expect($plain->getConfigDefinitions())->not->toHaveKeys(['app_late_world', 'app_late_secret']);
    expect($plain->getRcon()->getCommandInfo($plain->entryWorld))->not->toHaveKeys(['app_late_world', 'app_late_secret']);
    expect((new Config)->getDefinitions())->not->toHaveKeys(['app_late_world', 'app_late_secret']);
});

test('config commands use effective world metadata at boot and after late server registration', function (bool $late) {
    $server = new RconTestServer;
    $local  = new Config;
    $local->registerOption(new ConfigOption('app_local_secret', 'private-world-value', 'Local secret', secret: true));
    $local->registerOption(new ConfigOption('app_local_number', 3, 'Local number', min: 0, max: 20));
    $register = static function () use ($server): void {
        $server->registerConfigOption(new ConfigOption('app_local_secret', '', 'Server label'));
        $server->registerConfigOption(new ConfigOption('app_local_number', '', 'Server number label'));
    };

    if (! $late) {
        $register();
    }

    $world = $server->addWorldFromConfig(new WorldConfig('local', 'dm1', 64, 0, config: $local));

    if ($late) {
        $register();
    }

    $info = $server->getRcon()->getCommandInfo($world);
    expect($info['app_local_secret'])->toBe(['help' => 'Local secret', 'params' => '?r[value]']);
    expect($info['app_local_number'])->toBe(['help' => 'Local number', 'params' => '?i[value]']);
    expect($server->getRcon()->getCommandInfo($server->entryWorld)['app_local_number'])->toBe(['help' => 'Server number label', 'params' => '?r[value]']);

    $admin = $server->addClient($world);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets = [];
    $server->send($admin, new RconCmdChunk('app_local_secret; app_local_number 9'));
    $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));

    expect($lines)->toContain('app_local_secret = [hidden]', 'app_local_number = 9');
    expect(implode("\n", $lines))->not->toContain('private-world-value');
    expect($world->getConfigValue('app_local_number'))->toBe(9);
    expect($server->entryWorld->getConfigValue('app_local_number'))->toBe('');
})->with(['at boot' => false, 'late registration' => true]);

test('registered restart-only settings load at startup retain metadata and allow reads but not strict API mutations after start', function () {
    withRegistrationConfigFiles([
        'server.cfg' => "sv_registration_restart 7\nsv_world_registration_restart 9\nsv_world_add arena dm1 8 0 dm world.cfg\nsv_world_add other dm1 8 0 dm",
        'world.cfg'  => 'sv_world_registration_restart 11',
    ], function (string $directory): void {
        $server = new RconTestServer(config: restartRegistrationConfig(), configFile: $directory.'/server.cfg');
        $world  = $server->entryWorld;
        $other  = $server->getWorlds()[1];
        expect($server->isStarted())->toBeFalse();
        expect($server->getConfigValue('sv_registration_restart'))->toBe(7);
        expect($server->getConfigValue('sv_world_registration_restart'))->toBe(9);
        expect($world->getConfigValue('sv_world_registration_restart'))->toBe(11);
        expect($other->getConfigValue('sv_world_registration_restart'))->toBe(9);

        $server->setConfig('sv_registration_restart', 12);
        $world->setConfig('sv_registration_restart', 13);
        $world->setConfig('sv_world_registration_restart', 14);
        expect($server->getConfigValue('sv_registration_restart'))->toBe(13);
        expect($world->getConfigValue('sv_world_registration_restart'))->toBe(14);
        (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
        expect($server->isStarted())->toBeTrue();

        foreach (['sv_registration_restart' => 13, 'sv_world_registration_restart' => 14] as $name => $value) {
            expect($server->getConfigDefinition($name)['restart'] ?? false)->toBeTrue();
            expect($world->getConfig()->getDefinition($name)['restart'] ?? false)->toBeTrue();
            expect($world->getConfigValue($name))->toBe($value);
            expect($server->getRcon()->getCommandInfo($world)[$name]['help'])->toContain('startup config only');
            expect(fn () => $world->setConfig($name, 20))->toThrow(InvalidArgumentException::class, 'requires a server restart');
            expect(fn () => $server->setConfig($name, 20))->toThrow(InvalidArgumentException::class, 'requires a server restart');
            expect($world->getConfigValue($name))->toBe($value);
        }

        expect($server->getConfigValue('sv_registration_restart'))->toBe(13);
        expect($server->getConfigValue('sv_world_registration_restart'))->toBe(9);
        expect($other->getConfigValue('sv_registration_restart'))->toBe(13);
        expect($other->getConfigValue('sv_world_registration_restart'))->toBe(9);
    });
});

test('server and world setters share the same configuration mutability guard', function () {
    $server = new class(config: restartRegistrationConfig()) extends RconTestServer
    {
        /** @var list<string> */
        public array $checkedSettings = [];

        public function assertConfigMutable(string $name): void
        {
            $this->checkedSettings[] = $name;
            parent::assertConfigMutable($name);
        }
    };
    $world = $server->entryWorld;
    $server->setConfig('sv_registration_restart', 7);
    $world->setConfig('sv_world_registration_restart', 9);
    $world->setConfig('sv_registration_restart', 11);

    expect($server->checkedSettings)->toBe(['sv_registration_restart', 'sv_world_registration_restart', 'sv_registration_restart']);
    (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);

    expect(fn () => $server->setConfig('sv_registration_restart', 12))
        ->toThrow(InvalidArgumentException::class, 'sv_registration_restart requires a server restart; set it in the startup config');
    expect(fn () => $world->setConfig('sv_world_registration_restart', 12))
        ->toThrow(InvalidArgumentException::class, 'sv_world_registration_restart requires a server restart; set it in the startup config');
    expect($server->checkedSettings)->toHaveCount(5);
    expect($server->getConfigValue('sv_registration_restart'))->toBe(11);
    expect($world->getConfigValue('sv_world_registration_restart'))->toBe(9);
});

test('RCON can set registered restart-only settings before start and read but not mutate them after start', function (string $name) {
    $server = new RconTestServer(config: restartRegistrationConfig());
    $world  = $server->entryWorld;
    $admin  = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk($name.' 7'));
    expect($world->getConfigValue($name))->toBe(7);
    (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
    $server->socket($admin)->packets = [];

    $server->send($admin, new RconCmdChunk($name));
    $server->send($admin, new RconCmdChunk($name.' 9'));
    $server->send($admin, new RconCmdChunk($name));

    $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
    expect($lines)->toContain($name.' = 7', $name.' requires a server restart; set it in the startup config');
    expect(array_count_values($lines)[$name.' = 7'] ?? 0)->toBe(2);
    expect($lines)->not->toContain($name.' = 9');
    expect($world->getConfigValue($name))->toBe(7);
})->with(['sv_registration_restart', 'sv_world_registration_restart']);

test('forced and passed votes cannot bypass registered restart-only settings after start', function (string $name, bool $forced) {
    $server = new RconTestServer(config: restartRegistrationConfig());
    $world  = $server->entryWorld;
    $world->setConfig('sv_world_spam_protection', 0);
    $world->setConfig('sv_world_vote_delay', 0);
    $admin = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $votes = $world->getVoteController();
    $votes->addVoteOption('Before start', $name.' 7');
    $votes->addVoteOption('After start', $name.' 9');

    if ($forced) {
        $server->send($admin, new RconCmdChunk('force_vote option "Before start"'));
    } else {
        $server->send($admin, new ClCallVoteChunk('option', 'Before start', ''));
        expect($votes->isVoteRunning())->toBeTrue();
        $votes->tick($world);
    }

    expect($world->getConfigValue($name))->toBe(7);
    expect($votes->isVoteRunning())->toBeFalse();
    (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
    $server->socket($admin)->packets = [];

    if ($forced) {
        $server->send($admin, new RconCmdChunk('force_vote option "After start"'));
        $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
    } else {
        $server->send($admin, new ClCallVoteChunk('option', 'After start', ''));
        expect($votes->isVoteRunning())->toBeTrue();
        $votes->tick($world);
        $lines = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($admin)->chunks(SvChatChunk::class));
        expect(implode("\n", $lines))->toContain('Vote command failed:');
    }

    expect(implode("\n", $lines))->toContain($name.' requires a server restart');
    expect($world->getConfigValue($name))->toBe(7);
    expect($votes->isVoteRunning())->toBeFalse();
})->with(['sv_registration_restart', 'sv_world_registration_restart'])->with([false, true]);

test('runtime exec logs rejected registered restart-only settings and continues applying following live settings', function () {
    withRegistrationConfigFiles([
        'startup.cfg' => "sv_registration_restart 7\nsv_world_registration_restart 9",
        'runtime.cfg' => "sv_registration_restart 21\nsv_world_registration_live 11\nsv_world_registration_restart 22\nsv_world_registration_live 13",
    ], function (string $directory): void {
        $server = new RconTestServer(config: restartRegistrationConfig(), configFile: $directory.'/startup.cfg');
        $world  = $server->entryWorld;
        $other  = $server->addWorld('other');
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
        $errors   = [];
        $listener = function (string $line) use (&$errors): void {
            $errors[] = $line;
        };
        Console::listen($listener, Console::LEVEL_ERROR);

        try {
            $server->send($admin, new RconCmdChunk('exec "'.$directory.'/runtime.cfg"'));
        } finally {
            Console::unlisten($listener);
        }

        expect($errors)->toHaveCount(2);
        expect($errors[0])->toContain('[ERROR] [config]:', 'Cannot load', 'sv_registration_restart', $directory.'/runtime.cfg');
        expect($errors[1])->toContain('[ERROR] [config]:', 'Cannot load', 'sv_world_registration_restart', $directory.'/runtime.cfg');
        expect($world->getConfigValue('sv_registration_restart'))->toBe(7);
        expect($world->getConfigValue('sv_world_registration_restart'))->toBe(9);
        expect($server->getConfigValue('sv_registration_restart'))->toBe(7);
        expect($server->getConfigValue('sv_world_registration_restart'))->toBe(9);
        expect($world->getConfigValue('sv_world_registration_live'))->toBe(13);
        expect($other->getConfigValue('sv_world_registration_live'))->toBe(0);
        $lines = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
        expect($lines)->toContain('Loaded configuration: '.$directory.'/runtime.cfg');
    });
});
