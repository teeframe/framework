<?php

use PHPUnit\Framework\Assert;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\Server\ConfigCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\Vote\VoteOption;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClChangeInfoChunk;
use TeeFrame\Network\Chunks\Game\ClKillChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionListAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdAddChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\Config\ConfigFile;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Console;
use TeeFrame\Server\ServerInfoPacketBuilder;
use Tests\Support\RconTestServer;
use Tests\Support\RconTestSocket;

require_once __DIR__.'/../Support/RconTestServer.php';

class SkeletonConfigTestConfig extends Config
{
    protected const DEFINITIONS = parent::DEFINITIONS + [
        'app_option' => ['default' => 7, 'min' => 0, 'max' => 100, 'scope' => 'server', 'restart' => true, 'description' => 'Skeleton-specific integer setting'],
        'app_label'  => ['default' => '', 'bytes' => 63, 'scope' => 'server', 'description' => 'Skeleton-specific string setting'],
    ];
}

/**
 * @param  array<string, string>  $files
 * @param  Closure(string): void  $test
 */
function withConfigFiles(array $files, Closure $test): void
{
    $directory = sys_get_temp_dir().'/teeframe-config-'.bin2hex(random_bytes(8));
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

/** @return list<string> */
function configRconLines(RconTestSocket $socket): array
{
    return array_map(fn (RconLineChunk $chunk): string => $chunk->line, $socket->chunks(RconLineChunk::class));
}

test('cfg files preserve quoting escaping comments includes BOM and CRLF', function () {
    withConfigFiles([
        'server.cfg' => "\xEF\xBB\xBF# Server settings\r\nsv_name \"First\"; exec \"extra.cfg\" # relative include\r\nsv_name \"Final; # server\"\r\nsv_world_motd \"A \\\"quote\\\" and \\\\ path\"\r\nsv_password \"\"\r\n",
        'extra.cfg'  => "sv_rcon_max_tries 12\nexec \"nested.cfg\"\n",
        'nested.cfg' => "sv_name \"Included\"\nsv_world_pvp_score_limit 12\n",
    ], function (string $directory): void {
        $config = new Config;
        $config->loadFile($directory.'/server.cfg');

        expect($config->getString('sv_name'))->toBe('Final; # server');
        expect($config->getString('sv_world_motd'))->toBe('A "quote" and \\ path');
        expect($config->getString('sv_password'))->toBe('');
        expect($config->getInt('sv_rcon_max_tries'))->toBe(12);
        expect($config->getInt('sv_world_pvp_score_limit'))->toBe(12);
    });
});

test('invalid cfg files leave existing values intact and report a location without passwords', function () {
    withConfigFiles(['server.cfg' => "sv_name \"New name\"\nsv_rcon_password \"do-not-print-this\" trailing\n"], function (string $directory): void {
        $config = new Config;
        $config->set('sv_name', 'Original');

        try {
            $config->loadFile($directory.'/server.cfg');
            Assert::fail('Expected invalid configuration to be rejected');
        } catch (UnexpectedValueException $exception) {
            expect($exception->getMessage())->toContain('server.cfg:2:');
            expect($exception->getMessage())->not->toContain('do-not-print-this');
        }

        expect($config->getString('sv_name'))->toBe('Original');
        expect($config->has('sv_rcon_password'))->toBeFalse();
    });
});

test('cfg includes reject cycles and missing files', function () {
    withConfigFiles([
        'first.cfg'   => 'exec "second.cfg"',
        'second.cfg'  => 'exec "first.cfg"',
        'missing.cfg' => 'exec "not-found.cfg"',
    ], function (string $directory): void {
        expect(fn () => ConfigFile::read($directory.'/first.cfg'))->toThrow(UnexpectedValueException::class, 'Recursive configuration include');
        expect(fn () => ConfigFile::read($directory.'/missing.cfg'))->toThrow(UnexpectedValueException::class, 'Cannot read configuration file');
        expect(fn () => ConfigFile::read($directory.'/absent.cfg'))->toThrow(RuntimeException::class);
    });
});

test('world configs reject server settings without applying preceding values', function () {
    withConfigFiles(['world.cfg' => "sv_world_pvp_score_limit 10\nsv_name \"Not world scoped\""], function (string $directory): void {
        $config = new Config;
        expect(fn () => $config->loadFile($directory.'/world.cfg', worldOnly: true))->toThrow(UnexpectedValueException::class);
        expect($config->has('sv_world_pvp_score_limit'))->toBeFalse();
    });
});

test('configuration validates types ranges and durations', function (string $name, int|string $value) {
    expect(fn () => (new Config)->set($name, $value))->toThrow(InvalidArgumentException::class);
})->with([
    ['sv_port', 0], ['sv_port', 65536], ['sv_port', 'invalid'],
    ['sv_world_vote_duration', 61], ['sv_rcon_max_tries', 101], ['sv_world_pvp_score_limit', 'eight'],
    ['sv_register_on_master_server', 2], ['sv_kick_ban_duration', -1], ['sv_world_pvp_score_limit', -1],
    ['sv_world_vote_duration', 0], ['sv_rcon_max_tries', -1], ['sv_rcon_ban_duration', 1441],
    ['sv_world_pvp_warmup', PHP_INT_MAX], ['sv_world_pvp_unpause_timer', PHP_INT_MAX],
    ['sv_rcon_password', "bad\0password"], ['sv_world_motd', "\xff"],
]);

test('configuration truncates UTF-8 strings at the byte limit without splitting characters', function () {
    $config = new Config;
    $config->set('sv_name', str_repeat('🎮', 40));
    expect($config->getString('sv_name'))->toBe(str_repeat('🎮', 31));
    expect(mb_check_encoding($config->getString('sv_name'), 'UTF-8'))->toBeTrue();
});

test('unknown configuration settings are rejected', function () {
    expect(fn () => (new Config)->set('sv_unknown_setting', 1))->toThrow(InvalidArgumentException::class, 'Unknown configuration variable');

    withConfigFiles(['server.cfg' => 'sv_unknown_setting 1'], function (string $directory): void {
        expect(fn () => Config::fromFile($directory.'/server.cfg'))->toThrow(UnexpectedValueException::class, 'Unknown configuration variable');
    });
});

test('all built-in configuration names use the sv prefix', function () {
    foreach (array_keys(Config::definitions()) as $name) {
        expect(str_starts_with($name, 'sv_'))->toBeTrue();
    }
});

test('world overrides remain independent and constructor fallbacks survive inheritance', function () {
    $server = new Config;
    $first  = new Config(parent: $server);
    $second = new Config(parent: $server);

    expect($first->getInt('sv_world_pvp_score_limit', 7))->toBe(7);
    $server->set('sv_world_pvp_score_limit', 10);
    expect($first->getInt('sv_world_pvp_score_limit', 7))->toBe(10);
    $first->set('sv_world_pvp_score_limit', 20);
    $server->set('sv_world_pvp_score_limit', 30);
    expect($first->getInt('sv_world_pvp_score_limit'))->toBe(20);
    expect($second->getInt('sv_world_pvp_score_limit'))->toBe(30);
    $first->set('sv_world_pvp_score_limit', 0);
    expect($first->getInt('sv_world_pvp_score_limit'))->toBe(0);
});

test('startup cfg configures server identity world map gametype capacity scores and warmup', function () {
    withConfigFiles(['server.cfg' => "sv_name \"Configured server\"\nsv_world_add ctf ctf1 8 2 ctf\nsv_world_pvp_score_limit 42\nsv_world_pvp_time_limit 4\nsv_world_pvp_warmup 2\n"], function (string $directory): void {
        $server   = new RconTestServer(configFile: $directory.'/server.cfg');
        $world    = $server->entryWorld;
        $snapshot = $world->getGameController()->doSnap(new PlayerTee)[0];
        assert($snapshot instanceof ObjGameInfoItem);

        expect($server->getName())->toBe('Configured server');
        expect($world->getMap()->getName())->toBe('ctf1');
        expect($world->getGameController()->getGameType())->toBe('CTF');
        expect($world->getSlotsLimit())->toBe(8);
        expect($world->getSpectatorSlots())->toBe(2);
        expect([$snapshot->scoreLimit, $snapshot->timeLimit, $snapshot->warmupTimer])->toBe([42, 4, 2 * NetworkParams::TICKS_PER_SECOND]);
    });
});

test('a world cfg overrides startup world defaults without changing other worlds', function () {
    withConfigFiles([
        'server.cfg' => "sv_world_pvp_score_limit 10\nsv_world_add dm dm1 12 2 dm\nsv_world_add ctf ctf1 6 1 ctf \"world.cfg\"\n",
        'world.cfg'  => "sv_world_pvp_score_limit 25\n",
    ], function (string $directory): void {
        $server = new RconTestServer(configFile: $directory.'/server.cfg');
        $world  = $server->getWorlds()[1];

        expect($world->getConfigValue('sv_world_pvp_score_limit'))->toBe(25);
        expect($world->getSlotsLimit())->toBe(6);
        expect($world->getMap()->getName())->toBe('ctf1');
        expect($world->getGameController()->getGameType())->toBe('CTF');
        expect($server->entryWorld->getSlotsLimit())->toBe(12);
        expect($server->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(10);
        expect($server->entryWorld->getMap()->getName())->toBe('dm1');
    });
});

test('included vote files populate each world and preserve callable command sequences', function (bool $votesFirst) {
    $worlds = "sv_world_add first dm1 8 0 dm\nsv_world_add second dm1 8 0 dm\n";
    $votes  = "exec \"votes.cfg\"\n";
    withConfigFiles([
        'server.cfg' => $votesFirst ? $votes.$worlds : $worlds.$votes,
        'votes.cfg'  => "add_vote \"Switch #1\" \"sv_world_map ctf1; sv_world_game_type ctf\" # comment\nadd_vote \"Reload\" reload\nadd_vote \"RELOAD\" \"echo ignored\"\n",
    ], function (string $directory): void {
        $server           = new RconTestServer(configFile: $directory.'/server.cfg');
        [$first, $second] = $server->getWorlds();

        foreach ([$first, $second] as $world) {
            $options = $world->getVoteController()->getVoteOptions();
            expect(array_map(fn (VoteOption $option): string => $option->description, $options))->toBe(['Switch #1', 'Reload']);
            expect($options[0]->command)->toBe('sv_world_map ctf1; sv_world_game_type ctf');
            expect($options[1]->command)->toBe('reload');
            $menu = $world->getVoteController()->getVoteChunks(new PlayerTee)[0];
            assert($menu instanceof SvVoteOptionListAddChunk);
            expect($menu->descriptions)->toBe(['Switch #1', 'Reload']);
        }

        $admin = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('force_vote option "Switch #1"'));
        $server->tick();
        expect($first->getMap()->getName())->toBe('ctf1');
        expect($first->getGameController()->getGameType())->toBe('CTF');
        expect($second->getMap()->getName())->toBe('dm1');
        expect($second->getGameController()->getGameType())->toBe('DM');
        expect($first->getVoteController()->getVoteOptions())->toHaveCount(2);
    });
})->with(['before world declarations' => true, 'after world declarations' => false]);

test('world vote files override shared defaults without sharing live menus between worlds', function () {
    withConfigFiles([
        'server.cfg' => "exec \"votes.cfg\"\nsv_world_add first dm1 8 0 dm \"world.cfg\"\nsv_world_add second dm1 8 0 dm\n",
        'votes.cfg'  => "add_vote Shared \"echo shared\"\nadd_vote Reload reload\n",
        'world.cfg'  => "add_vote RELOAD \"echo local\"\nadd_vote Local \"echo local-only\"\n",
    ], function (string $directory): void {
        $server           = new RconTestServer(configFile: $directory.'/server.cfg');
        [$first, $second] = $server->getWorlds();
        expect(array_map(fn (VoteOption $option): string => $option->description, $first->getVoteController()->getVoteOptions()))->toBe(['Shared', 'RELOAD', 'Local']);
        expect($first->getVoteController()->getVoteOption('Reload')?->command)->toBe('echo local');
        expect($second->getVoteController()->getVoteOption('Reload')?->command)->toBe('reload');
        $first->getVoteController()->removeVoteOption('Shared');
        expect($second->getVoteController()->getVoteOption('Shared')?->command)->toBe('echo shared');
        $third = $server->addWorld('third');
        expect(array_map(fn (VoteOption $option): string => $option->description, $third->getVoteController()->getVoteOptions()))->toBe(['Shared', 'Reload']);
    });
});

test('invalid vote declarations report their config line and preserve existing settings and votes', function (string $line) {
    withConfigFiles([
        'server.cfg'  => "sv_name Original\nadd_vote Existing \"echo existing\"\n",
        'invalid.cfg' => "sv_name Changed\nadd_vote New reload\n".$line,
    ], function (string $directory): void {
        $config = Config::fromFile($directory.'/server.cfg');
        expect(fn () => $config->loadFile($directory.'/invalid.cfg'))->toThrow(UnexpectedValueException::class, 'invalid.cfg:3:');
        expect($config->getString('sv_name'))->toBe('Original');
        $options = $config->getVoteOptions();
        expect($options)->toHaveCount(1);
        expect($options[0]->description)->toBe('Existing');
        expect($options[0]->command)->toBe('echo existing');
    });
})->with([
    'missing arguments' => 'add_vote',
    'missing command'   => 'add_vote "Missing command"',
    'empty description' => 'add_vote "" reload',
    'long description'  => 'add_vote "'.str_repeat('a', 64).'" reload',
    'unclosed quote'    => 'add_vote "Unclosed description reload',
]);

test('runtime exec adds vote menu entries only to initialized clients in its world and skips duplicates', function () {
    withConfigFiles(['votes.cfg' => "add_vote Reload reload\nadd_vote Map \"sv_world_map ctf1\"\n"], function (string $directory): void {
        $server              = new RconTestServer;
        $admin               = $server->addClient();
        $ready               = $server->addClient(port: 8301, state: ConnectionSlot::STATE_READY);
        $uninitialized       = $server->addClient(port: 8302, state: ConnectionSlot::STATE_READY);
        $other               = $server->addClient($server->addWorld('other'), port: 8303);
        $ready->readyToEnter = true;
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $command = 'exec "'.$directory.'/votes.cfg"';
        $server->send($admin, new RconCmdChunk($command));
        $server->send($admin, new RconCmdChunk($command));

        foreach ([$admin, $ready] as $client) {
            $updates = $server->socket($client)->chunks(SvVoteOptionAddChunk::class);
            expect(array_map(fn (SvVoteOptionAddChunk $chunk): string => $chunk->description, $updates))->toBe(['Reload', 'Map']);
        }
        expect($server->socket($uninitialized)->chunks(SvVoteOptionAddChunk::class))->toBeEmpty();
        expect($server->socket($other)->chunks(SvVoteOptionAddChunk::class))->toBeEmpty();
        expect($server->entryWorld->getVoteController()->getVoteOptions())->toHaveCount(2);
        expect($other->world()->getVoteController()->getVoteOptions())->toBeEmpty();
    });
});

test('runtime config votes require registered server commands before updating the menu', function () {
    withConfigFiles(['votes.cfg' => "add_vote Invalid missing_command\nsv_name Changed\n"], function (string $directory): void {
        $server = new RconTestServer;
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('exec "'.$directory.'/votes.cfg"'));

        expect(configRconLines($server->socket($admin)))->toContain('Vote options must use registered server commands');
        expect($server->entryWorld->getVoteController()->getVoteOptions())->toBeEmpty();
        expect($server->socket($admin)->chunks(SvVoteOptionAddChunk::class))->toBeEmpty();
        expect($server->getName())->toBe('RCON test');
    });
});

test('server and rcon passwords from cfg are used for authentication', function () {
    withConfigFiles(['server.cfg' => "sv_password \"join-secret\"\nsv_rcon_password \"admin-secret\"\n"], function (string $directory): void {
        $server = new RconTestServer(configFile: $directory.'/server.cfg');
        $client = $server->addClient(state: ConnectionSlot::STATE_CONNECTING);
        $server->send($client, new InfoChunk('0.6 626fce9a778df4d4', 'join-secret'));
        expect($client->state)->toBe(ConnectionSlot::STATE_LOADING);
        $server->send($client, new RconAuthChunk('', 'test-secret'));
        expect($client->rcon()->authenticated)->toBeFalse();
        $server->send($client, new RconAuthChunk('', 'admin-secret'));
        expect($client->rcon()->authenticated)->toBeTrue();
    });
});

test('configured motd is sent during the handshake and live updates reach the world', function () {
    withConfigFiles(['server.cfg' => 'sv_world_motd "First\\nSecond"'], function (string $directory): void {
        $server  = new RconTestServer(configFile: $directory.'/server.cfg');
        $loading = $server->addClient(state: ConnectionSlot::STATE_LOADING);
        $server->send($loading, new ReadyChunk);
        expect($server->socket($loading)->chunks(SvMotdChunk::class)[0]->text)->toBe("First\nSecond");
        $client                = $server->addClient(port: 8301);
        $loading->readyToEnter = true;
        $server->entryWorld->setConfig('sv_world_motd', 'Updated\\nMOTD');
        $loading->chunks()->send();
        $client->chunks()->send();
        expect($server->socket($client)->chunks(SvMotdChunk::class)[0]->text)->toBe("Updated\nMOTD");
        expect($server->socket($loading)->chunks(SvMotdChunk::class)[1]->text)->toBe("Updated\nMOTD");
    });
});

test('info uses the configured world motd and skips empty lines', function (string $motd, array $expected) {
    $server = new RconTestServer(config: new Config(['sv_world_motd' => $motd]));
    $first  = $server->addClient();
    $other  = $server->addClient($server->addWorld('other'), port: 8301);
    $other->world()->setConfig('sv_world_motd', 'Other world');
    foreach ([$first, $other] as $client) {
        $server->socket($client)->packets = [];
        $server->send($client, new ClSayChunk(false, '/info'));
    }

    $firstLines = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($first)->chunks(SvChatChunk::class));
    $otherLines = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($other)->chunks(SvChatChunk::class));
    expect(array_slice($firstLines, 2))->toBe($expected);
    expect(array_slice($otherLines, 2))->toBe(['Other world']);
})->with([
    'empty'            => ['', []],
    'escaped newlines' => ['First\\n\\nSecond', ['First', 'Second']],
    'literal newlines' => ["First\n\nSecond", ['First', 'Second']],
]);

test('default connection world selection rejects an empty list and selects the first available world', function () {
    $clock  = new TickHandler(100);
    $server = new class($clock) extends AbstractServerInstance
    {
        protected function boot(): void {}

        public function selectEntryWorld(): AbstractWorld
        {
            return $this->selectWorldForNewConnection();
        }

        public function registerTestWorld(int $index, AbstractWorld $world): void
        {
            $this->worlds[$index] = $world;
        }
    };

    expect(fn () => $server->selectEntryWorld())->toThrow(RuntimeException::class, 'No worlds are available');

    $first  = new TestWorld($clock, new Map, $server);
    $second = new TestWorld($clock, new Map, $server);
    $server->registerTestWorld(4, $first);
    $server->registerTestWorld(9, $second);
    expect($server->selectEntryWorld())->toBe($first);
});

test('world settings use the sv_world prefix', function () {
    foreach (Config::definitions() as $name => $definition) {
        if ($definition['scope'] !== 'world') {
            continue;
        }

        expect(str_starts_with($name, 'sv_world_'))->toBeTrue();

    }

    expect(Config::definition('sv_rcon_password')['scope'])->toBe('server');
});

test('server instances load configuration from a file path', function () {
    withConfigFiles(['server.cfg' => 'sv_name "Config only"'], function (string $directory): void {
        $server = new class(new TickHandler, $directory.'/server.cfg') extends AbstractServerInstance
        {
            protected function boot(): void {}

            protected function selectWorldForNewConnection(): AbstractWorld
            {
                throw new LogicException('No test connections');
            }
        };
        expect($server->getName())->toBe('Config only');
    });
});

test('world declarations reject invalid limits identifiers and map names', function (string $name, string $map, int $clients, int $spectators) {
    expect(fn () => new WorldConfig($name, $map, $clients, $spectators))->toThrow(InvalidArgumentException::class);
})->with([
    ['dm', 'dm1', 0, 0], ['dm', 'dm1', 65, 0], ['dm', 'dm1', 4, -1], ['dm', 'dm1', 4, 5],
    ['', 'dm1', 4, 0], [' ', 'dm1', 4, 0], ["dm\0", 'dm1', 4, 0],
    ['dm', '../dm1', 4, 0], ['dm', '', 4, 0], ['dm', '..', 4, 0],
]);

test('world configuration includes retain their scope and detect recursion', function (string $contents, string $error) {
    withConfigFiles([
        'server.cfg' => 'sv_world_add dm dm1 4 1 dm "world.cfg"',
        'world.cfg'  => $contents,
    ], function (string $directory) use ($error): void {
        expect(fn () => Config::fromFile($directory.'/server.cfg'))->toThrow(UnexpectedValueException::class, $error);
    });
})->with([
    ['sv_name "Not world scoped"', 'Server setting cannot be changed in a world config'],
    ['sv_world_add nested dm1 4 0 dm', 'World configs cannot add other worlds'],
    ['exec "server.cfg"', 'Recursive configuration include'],
]);

test('duplicate world declarations leave the previous configuration intact', function () {
    withConfigFiles(['server.cfg' => "sv_name Changed\nsv_world_add dm dm1 4 0 dm\nsv_world_add dm ctf1 8 2 ctf"], function (string $directory): void {
        $config = new Config(['sv_name' => 'Original']);
        expect(fn () => $config->loadFile($directory.'/server.cfg'))->toThrow(UnexpectedValueException::class, 'World already configured');
        expect($config->getString('sv_name'))->toBe('Original');
        expect($config->getWorlds())->toBeEmpty();
    });
});

test('world declarations inside exec files use paths relative to the containing file', function () {
    withConfigFiles([
        'server.cfg'   => "sv_world_pvp_score_limit 10\nexec \"worlds.cfg\"",
        'worlds.cfg'   => 'sv_world_add arena ctf1 12 2 ctf "arena.cfg"',
        'arena.cfg'    => "sv_world_pvp_score_limit 30\nexec \"messages.cfg\"",
        'messages.cfg' => 'sv_world_motd "Arena rules"',
    ], function (string $directory): void {
        $server = new RconTestServer(configFile: $directory.'/server.cfg');
        $world  = $server->entryWorld;
        expect($world->identifier)->toBe('arena');
        expect($world->getSlotsLimit())->toBe(12);
        expect($world->getSpectatorSlots())->toBe(2);
        expect($world->getConfigValue('sv_world_pvp_score_limit'))->toBe(30);
        expect($world->getConfigValue('sv_world_motd'))->toBe('Arena rules');
    });
});

test('each configured world enforces its own client and spectator capacity', function () {
    withConfigFiles(['server.cfg' => "sv_world_add first dm1 1 0 dm\nsv_world_add second dm1 2 1 dm"], function (string $directory): void {
        $server           = new RconTestServer(configFile: $directory.'/server.cfg');
        [$first, $second] = $server->getWorlds();
        $server->addClient($first, port: 9000);
        expect(fn () => $server->addClient($first, port: 9001))->toThrow(RuntimeException::class, 'No free RCON test slot');
        $playing    = $server->addClient($second, port: 9002);
        $spectating = $server->addClient($second, port: 9003);
        expect($playing->playerTee()->team)->toBe(GameConstants::TEAM_RED);
        expect($spectating->playerTee()->team)->toBe(GameConstants::TEAM_SPECTATORS);
        expect(fn () => $server->addClient($second, port: 9004))->toThrow(RuntimeException::class, 'No free RCON test slot');
        $worlds = $server->getWorlds();
        assert($worlds !== []);
        $info = (new ServerInfoPacketBuilder)->buildInfo($worlds, $server->getConnections(), $server->getName(), false);
        expect($info['max_clients'])->toBe(3);
        expect($info['max_players'])->toBe(2);
        expect($info['num_players'])->toBe(2);
    });
});

test('server capacity is the sum of worlds without an additional global cap', function () {
    withConfigFiles(['server.cfg' => "sv_world_add first dm1 64 4 dm\nsv_world_add second ctf1 48 3 ctf"], function (string $directory): void {
        $server = new RconTestServer(configFile: $directory.'/server.cfg');
        $worlds = $server->getWorlds();
        assert($worlds !== []);
        $info = (new ServerInfoPacketBuilder)->buildInfo($worlds, [], $server->getName(), false);
        expect($info['max_clients'])->toBe(112);
        expect($info['max_players'])->toBe(105);
    });
});

test('authenticated rcon can add a world with independent settings without moving its caller', function () {
    withConfigFiles(['world.cfg' => 'sv_world_pvp_score_limit 30'], function (string $directory): void {
        $server   = new RconTestServer;
        $admin    = $server->addClient();
        $original = $admin->world();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('sv_world_add arena ctf1 12 2 ctf "'.$directory.'/world.cfg"'));
        expect($server->getWorlds())->toHaveCount(2);
        $added = $server->getWorlds()[1];
        expect($added->identifier)->toBe('arena');
        expect($added->getMap()->getName())->toBe('ctf1');
        expect($added->getGameController()->getGameType())->toBe('CTF');
        expect($added->getSlotsLimit())->toBe(12);
        expect($added->getSpectatorSlots())->toBe(2);
        expect($added->getConfigValue('sv_world_pvp_score_limit'))->toBe(30);
        expect($added->getCurrentTick())->toBe($original->getCurrentTick());
        expect($admin->world())->toBe($original);
        expect($original->getSlotsLimit())->toBe(64);
        expect($original->getSpectatorSlots())->toBe(0);
        expect(configRconLines($server->socket($admin)))->toContain('Added world: arena');
        $server->send($admin, new RconCmdChunk('sv_world_add arena dm1 4 0 dm'));
        expect($server->getWorlds())->toHaveCount(2);
        expect($server->getWorlds()[1])->toBe($added);
    });
});

test('failed world creation never registers a partial world', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('sv_world_add broken missing-map 12 2 dm'));
    expect($server->getWorlds())->toHaveCount(1);
    expect($server->getWorlds()[0])->toBe($admin->world());
});

test('rcon exec can add worlds from configuration without changing existing capacities', function () {
    withConfigFiles(['extra.cfg' => 'sv_world_add extra ctf1 10 2 ctf'], function (string $directory): void {
        $server = new RconTestServer;
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('exec "'.$directory.'/extra.cfg"'));
        expect($server->getWorlds())->toHaveCount(2);
        expect($server->getWorlds()[0]->getSlotsLimit())->toBe(64);
        expect($server->getWorlds()[1]->getSlotsLimit())->toBe(10);
        expect($server->getWorlds()[1]->getSpectatorSlots())->toBe(2);
    });
});

test('world creation cannot be requested through chat or unauthenticated rcon', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconCmdChunk('sv_world_add extra dm1 4 0 dm'));
    $server->send($client, new ClSayChunk(false, '/sv_world_add extra dm1 4 0 dm'));
    expect($server->getWorlds())->toHaveCount(1);
});

test('skeleton-specific configuration definitions are isolated from framework defaults', function () {
    $config = new SkeletonConfigTestConfig(['app_option' => 12, 'app_label' => 'Local']);
    expect($config->getInt('app_option'))->toBe(12);
    expect($config->getString('app_label'))->toBe('Local');
    expect(fn () => $config->set('app_option', 101))->toThrow(InvalidArgumentException::class);
    expect(fn () => $config->set('app_option', 'invalid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Config::definition('app_option'))->toThrow(InvalidArgumentException::class);
    expect(array_key_exists('app_option', Config::definitions()))->toBeFalse();

    $child = new Config(parent: $config);
    expect($child->getInt('app_option'))->toBe(12);
});

test('skeleton configuration files and includes use their own definitions', function () {
    withConfigFiles([
        'server.cfg' => "app_option 12\nexec \"extra.cfg\"\nsv_world_add dm dm1 8 2 dm",
        'extra.cfg'  => 'app_label "Local setting"',
    ], function (string $directory): void {
        $config = SkeletonConfigTestConfig::fromFile($directory.'/server.cfg');
        expect($config)->toBeInstanceOf(SkeletonConfigTestConfig::class);
        expect($config->getInt('app_option'))->toBe(12);
        expect($config->getString('app_label'))->toBe('Local setting');
        expect($config->getWorlds())->toHaveCount(1);
        expect(fn () => Config::fromFile($directory.'/server.cfg'))->toThrow(UnexpectedValueException::class, 'Unknown configuration variable');
    });
});

test('skeleton-specific settings are available through RCON with their own metadata and restart checks', function () {
    $config  = new SkeletonConfigTestConfig(['sv_rcon_password' => 'test-secret']);
    $server  = new RconTestServer(config: $config);
    $admin   = $server->addClient();
    $command = $server->entryWorld->findCommand('app_option', AbstractCommand::TYPE_SERVER);
    expect($command)->toBeInstanceOf(ConfigCommand::class);
    assert($command instanceof ConfigCommand);
    expect($command->getParams())->toBe('?i[value]');
    expect($command->getDescription())->toContain('Skeleton-specific integer setting', 'startup config only');

    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('app_option 20; app_label "Local"'));
    expect($config->getInt('app_option'))->toBe(20);
    expect($config->getString('app_label'))->toBe('Local');
    expect(configRconLines($server->socket($admin)))->toContain('app_option = 20', 'app_label = Local');

    (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
    $server->send($admin, new RconCmdChunk('app_option 21'));
    expect($config->getInt('app_option'))->toBe(20);
    expect(implode("\n", configRconLines($server->socket($admin))))->toContain('requires a server restart');

    $plainServer = new RconTestServer;
    expect($plainServer->entryWorld->findCommand('app_option', AbstractCommand::TYPE_SERVER))->toBeNull();
});

test('runtime exec accepts skeleton-specific settings using the server configuration schema', function () {
    withConfigFiles(['local.cfg' => 'app_label "From file"'], function (string $directory): void {
        $config = new SkeletonConfigTestConfig(['sv_rcon_password' => 'test-secret']);
        $server = new RconTestServer(config: $config);
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('exec "'.$directory.'/local.cfg"'));
        expect($config->getString('app_label'))->toBe('From file');
    });
});

test('the world creation command advertises its arguments', function () {
    $server  = new RconTestServer;
    $command = $server->entryWorld->findCommand('sv_world_add', AbstractCommand::TYPE_SERVER);
    expect($command)->not->toBeNull();
    assert($command !== null);
    expect($command->getParams())->toBe('s[identifier] s[map] i[client_limit] i[spectator_slots] ?s[game_type] ?s[config_file]');

});

test('framework configuration owns the listening address and port', function () {
    $config = new Config;
    expect($config->getString('sv_bind_address'))->toBe('0.0.0.0');
    expect($config->getInt('sv_port'))->toBe(8303);

    withConfigFiles(['server.cfg' => "sv_bind_address \"::1\"\nsv_port 8400"], function (string $directory): void {
        $configured = Config::fromFile($directory.'/server.cfg');
        expect($configured->getString('sv_bind_address'))->toBe('::1');
        expect($configured->getInt('sv_port'))->toBe(8400);
        expect(Config::definition('sv_bind_address')['restart'] ?? false)->toBeTrue();
        expect(Config::definition('sv_port')['restart'] ?? false)->toBeTrue();
    });
});

test('configuration commands are server-only and have descriptions and typed optional parameters', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;

    foreach (Config::definitions() as $name => $definition) {
        $command = $world->findCommand($name, AbstractCommand::TYPE_SERVER);
        expect($command)->toBeInstanceOf(ConfigCommand::class);
        assert($command instanceof ConfigCommand);
        expect($command->getParams())->toBe(is_int($definition['default']) ? '?i[value]' : '?r[value]');
        expect($command->getDescription())->toContain($definition['description']);
        expect($world->findCommand($name, AbstractCommand::TYPE_CHAT))->toBeNull();
    }
});

test('custom controllers initialize after world setup and receive committed configuration changes', function () {
    $clock  = new TickHandler(100);
    $config = new class(['mod_setting' => 4]) extends Config
    {
        protected const DEFINITIONS = parent::DEFINITIONS + [
            'mod_setting' => ['default' => 0, 'min' => 0, 'max' => 100, 'scope' => 'world', 'description' => 'Custom controller setting'],
        ];
    };
    $server     = new RconTestServer(clock: $clock, config: $config, activatePvpSettings: false);
    $controller = new class($clock) extends AbstractGameController
    {
        public int $setting    = 0;
        public int $readyCalls = 0;

        /** @var list<array{string, int|string, int}> */
        public array $updates = [];

        public function getGameType(): string
        {
            return 'Custom';
        }

        public function onWorldReady(): void
        {
            assert($this->world !== null);
            $this->readyCalls++;
            $this->setting                             = $this->world->getConfig()->getInt('mod_setting');
            $this->world->getTuneController()->gravity = $this->setting;
        }

        public function onConfigChanged(string $name, int|string $value): void
        {
            if ($name === 'mod_setting') {
                assert($this->world !== null);
                $this->setting   = (int) $value;
                $this->updates[] = [$name, $value, $this->world->getConfig()->getInt($name)];
            }
        }
    };
    $world = new TestWorldWithController($clock, new Map, $controller, $server, config: $config);

    expect($controller->readyCalls)->toBe(1);
    expect($controller->setting)->toBe(4);
    expect($world->getTuneController()->gravity)->toBe(4);
    $world->setConfig('MOD_SETTING', '9');
    expect($controller->setting)->toBe(9);
    expect($controller->updates)->toBe([['mod_setting', 9, 9]]);
    expect($controller->readyCalls)->toBe(1);
    expect($server->getConfigValue('mod_setting'))->toBe(4);

    expect(fn () => $world->setConfig('mod_setting', 'invalid'))->toThrow(InvalidArgumentException::class);
    expect($controller->updates)->toHaveCount(1);
    expect($world->getConfigValue('mod_setting'))->toBe(9);
});

test('PvP controllers initialize and update configured warmup without requiring a game layer', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestPvpWorldWithController($clock, new Map, $controller, config: new Config(['sv_world_pvp_warmup' => 2]));
    $snapshot   = $controller->doSnap(new PlayerTee)[0];
    assert($snapshot instanceof ObjGameInfoItem);
    expect($world->getMap()->getGameLayer())->toBeNull();
    expect($snapshot->warmupTimer)->toBe(2 * NetworkParams::TICKS_PER_SECOND);

    $world->setConfig('sv_world_pvp_warmup', 3);
    $snapshot = $controller->doSnap(new PlayerTee)[0];
    assert($snapshot instanceof ObjGameInfoItem);
    expect($snapshot->warmupTimer)->toBe(3 * NetworkParams::TICKS_PER_SECOND);
    expect(fn () => $world->setConfig('sv_world_pvp_warmup', -1))->toThrow(InvalidArgumentException::class);
    expect($world->getConfigValue('sv_world_pvp_warmup'))->toBe(3);
});

test('PvP settings are opt-in without disabling the default PvP gameplay', function () {
    $clock  = new TickHandler(100);
    $server = new class($clock) extends AbstractServerInstance
    {
        protected function boot(): void {}
    };
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, $server);
    $snapshot   = $controller->doSnap(new PlayerTee)[0];
    assert($snapshot instanceof ObjGameInfoItem);

    expect($server->activatePvpSettings)->toBeFalse();
    expect($snapshot->scoreLimit)->toBe(0);
    expect($world->findCommand('sv_world_map', AbstractCommand::TYPE_SERVER))->not->toBeNull();
    expect(array_any(array_keys($world->getCommandMap(AbstractCommand::TYPE_SERVER)), fn (string $name): bool => str_starts_with($name, 'sv_world_pvp_')))->toBeFalse();
});

test('PvP settings appear in RCON commands autocomplete and cmdlist only when activated', function (bool $activated) {
    $server = new RconTestServer(activatePvpSettings: $activated);
    $admin  = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    foreach ($server->entryWorld->getCommands() as $_) {
        $server->getRcon()->update();
    }

    $advertised = array_map(fn (RconCmdAddChunk $chunk): string => $chunk->name, $server->socket($admin)->chunks(RconCmdAddChunk::class));
    expect(array_any($advertised, fn (string $name): bool => str_starts_with($name, 'sv_world_pvp_')))->toBe($activated);
    expect($server->entryWorld->findCommand('sv_world_pvp_score_limit', AbstractCommand::TYPE_SERVER) !== null)->toBe($activated);

    $server->socket($admin)->packets = [];
    $server->send($admin, new RconCmdChunk('cmdlist'));
    expect(str_contains(implode("\n", configRconLines($server->socket($admin))), 'sv_world_pvp_'))->toBe($activated);
})->with([false, true]);

test('disabled PvP settings cannot be queried or changed through server and world APIs', function (string $name) {
    $server = new RconTestServer(activatePvpSettings: false);
    $world  = $server->entryWorld;

    expect(fn () => $server->setConfig($name, 12))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    expect(fn () => $server->getConfigValue($name))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    expect(fn () => $world->setConfig($name, 12))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    expect(fn () => $world->getConfigValue($name))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    expect($world->getConfig()->has(strtolower($name)))->toBeFalse();
})->with(['sv_world_pvp_score_limit', 'SV_WORLD_PVP_SCORE_LIMIT']);

test('startup configuration enforces PvP activation for server includes world files and preloaded objects', function (bool $activated, bool $worldFile, bool $preloaded) {
    withConfigFiles([
        'server.cfg' => $worldFile ? 'sv_world_add arena dm1 8 0 dm "world.cfg"' : 'exec "pvp.cfg"',
        'world.cfg'  => 'exec "pvp.cfg"',
        'pvp.cfg'    => 'sv_world_pvp_score_limit 12',
    ], function (string $directory) use ($activated, $worldFile, $preloaded): void {
        $config = $preloaded ? Config::fromFile($directory.'/server.cfg') : $directory.'/server.cfg';
        $create = fn () => new class(new TickHandler, $config, $activated) extends AbstractServerInstance
        {
            protected function boot(): void {}
        };

        if (! $activated) {
            expect($create)->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');

            return;
        }

        $server   = $create();
        $settings = $worldFile ? $server->getConfig()->getWorlds()[0]->settings : $server->getConfig();
        expect($settings->getInt('sv_world_pvp_score_limit'))->toBe(12);
    });
})->with([false, true])->with(['server include' => false, 'world file' => true])->with(['file path' => false, 'preloaded object' => true]);

test('disabled PvP settings reject direct world overrides before adding a world', function () {
    $clock  = new TickHandler(100);
    $server = new RconTestServer(clock: $clock, activatePvpSettings: false);
    $config = new WorldConfig('arena', 'dm1', 8, 0);
    $config->settings->set('sv_world_pvp_score_limit', 12);

    expect(fn () => $server->addWorldFromConfig($config))->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    expect($server->getWorlds())->toHaveCount(1);
    expect(fn () => new TestWorldWithController($clock, new Map, new PvpGameController($clock), $server, config: $config->settings))
        ->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');

    withConfigFiles(['world.cfg' => 'sv_world_pvp_score_limit 12'], function (string $directory) use ($clock, $server): void {
        expect(fn () => new TestWorldWithController($clock, new Map, new PvpGameController($clock), $server, configFile: $directory.'/world.cfg'))
            ->toThrow(InvalidArgumentException::class, 'PvP settings are disabled');
    });
});

test('runtime RCON and exec apply PvP settings only when activated', function (bool $activated) {
    withConfigFiles(['runtime.cfg' => "sv_world_pvp_score_limit 12\nsv_name Applied"], function (string $directory) use ($activated): void {
        $server = new RconTestServer(activatePvpSettings: $activated);
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('sv_world_pvp_score_limit 8'));
        expect($server->entryWorld->getConfig()->getInt('sv_world_pvp_score_limit'))->toBe($activated ? 8 : 0);

        $server->send($admin, new RconCmdChunk('exec "'.$directory.'/runtime.cfg"'));
        expect($server->entryWorld->getConfig()->getInt('sv_world_pvp_score_limit'))->toBe($activated ? 12 : 0);
        expect($server->getName())->toBe($activated ? 'Applied' : 'RCON test');

        if (! $activated) {
            expect(configRconLines($server->socket($admin)))->toContain('Unknown command: sv_world_pvp_score_limit', 'PvP settings are disabled: sv_world_pvp_score_limit');
        }
    });
})->with([false, true]);

test('PvP activation is isolated between servers and preserves custom configuration schemas', function () {
    $enabled  = new RconTestServer(activatePvpSettings: true);
    $disabled = new RconTestServer(config: new SkeletonConfigTestConfig(['app_option' => 12]), activatePvpSettings: false);
    $enabled->entryWorld->setConfig('sv_world_pvp_score_limit', 18);
    $disabled->setConfig('app_option', 20);

    expect($enabled->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(18);
    expect($disabled->getConfigValue('app_option'))->toBe(20);
    expect($disabled->entryWorld->findCommand('app_option', AbstractCommand::TYPE_SERVER))->toBeInstanceOf(ConfigCommand::class);
    expect($disabled->entryWorld->findCommand('sv_world_pvp_score_limit', AbstractCommand::TYPE_SERVER))->toBeNull();
    expect($enabled->entryWorld->findCommand('sv_world_pvp_score_limit', AbstractCommand::TYPE_SERVER))->toBeInstanceOf(ConfigCommand::class);
});

test('rcon configuration changes are private and affect only the current world unless server scoped', function () {
    $server = new RconTestServer;
    $other  = $server->addWorld('other');
    $admin  = $server->addClient();
    $guest  = $server->addClient(port: 8301);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($guest)->packets = [];
    $server->send($admin, new RconCmdChunk('sv_world_pvp_score_limit 12; sv_name "Renamed server"'));

    expect($server->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(12);
    expect($server->entryWorld->getSlotsLimit())->toBe(64);
    expect($server->entryWorld->getSpectatorSlots())->toBe(0);
    expect($other->getConfigValue('sv_world_pvp_score_limit'))->toBe(0);
    expect($other->getSlotsLimit())->toBe(64);
    expect($server->getName())->toBe('Renamed server');
    expect(configRconLines($server->socket($admin)))->toContain('sv_world_pvp_score_limit = 12');
    expect($server->socket($guest)->chunks(RconLineChunk::class))->toBeEmpty();

    $server->send($guest, new RconCmdChunk('sv_world_pvp_score_limit 99'));
    $server->send($guest, new ClSayChunk(false, '/sv_world_pvp_score_limit 99'));
    expect($server->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(12);
});

test('rcon exec loads a cfg and nested includes through the same settings path', function () {
    withConfigFiles([
        'runtime.cfg' => "sv_world_pvp_score_limit 15\nexec \"extra.cfg\"",
        'extra.cfg'   => 'sv_name "Loaded from cfg"',
    ], function (string $directory): void {
        $server = new RconTestServer;
        $admin  = $server->addClient();
        $server->send($admin, new RconAuthChunk('', 'test-secret', false));
        $server->send($admin, new RconCmdChunk('exec "'.$directory.'/runtime.cfg"'));
        expect($server->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(15);
        expect($server->getName())->toBe('Loaded from cfg');
        expect(configRconLines($server->socket($admin)))->toContain('Loaded configuration: '.$directory.'/runtime.cfg');
    });
});

test('rcon never echoes passwords and password changes revoke existing sessions', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $other  = $server->addClient(port: 8301);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($other, new RconAuthChunk('', 'test-secret', false));
    $logs     = [];
    $listener = function (string $line) use (&$logs): void {
        $logs[] = $line;
    };
    Console::listen($listener);

    try {
        $server->send($admin, new RconCmdChunk('sv_password "new-join-secret"; sv_rcon_password "new-admin-secret"; sv_world_pvp_score_limit 99'));
        $server->getRcon()->update();
        $admin->chunks()->send();
        $other->chunks()->send();
        $output = implode("\n", [...$logs, ...configRconLines($server->socket($admin)), ...configRconLines($server->socket($other))]);
        expect($output)->not->toContain('new-join-secret', 'new-admin-secret');
        expect($admin->rcon()->authenticated)->toBeFalse();
        expect($other->rcon()->authenticated)->toBeFalse();
        expect($server->entryWorld->getConfigValue('sv_world_pvp_score_limit'))->toBe(0);
        $server->send($admin, new RconAuthChunk('', 'new-admin-secret', false));
        expect($admin->rcon()->authenticated)->toBeTrue();
    } finally {
        Console::unlisten($listener);
    }
});

test('startup-only settings cannot be changed after the server starts', function (string $name, int|string $value) {
    $server = new RconTestServer;
    (new ReflectionProperty(AbstractServerInstance::class, 'started'))->setValue($server, true);
    expect(fn () => $server->setConfig($name, $value))->toThrow(InvalidArgumentException::class, 'requires a server restart');
})->with([['sv_port', 8400], ['sv_bind_address', '127.0.0.1'], ['sv_register_on_master_server', 1]]);

test('per-IP capacity includes loading connections and equivalent IP addresses', function () {
    $server = new RconTestServer;
    $server->setConfig('sv_max_clients_per_ip', 1);
    $server->connect('192.0.2.1', 9000);
    $rejected = $server->connect('::ffff:192.0.2.1', 9001);
    expect($server->getConnections())->toHaveCount(1);
    expect($rejected->packets[0])->toBeInstanceOf(ControlMessage::class);
    assert($rejected->packets[0] instanceof ControlMessage);
    expect($rejected->packets[0]->getControlMessage())->toBe(NetworkMessages::CONTROL_CLOSE);
    $server->connect('192.0.2.2', 9002);
    expect($server->getConnections())->toHaveCount(2);
});

test('browser reply limits reset using the injected clock and zero disables the limit', function () {
    $clock = new class extends TickHandler
    {
        public int $now = 0;

        public function getTime(): int
        {
            return $this->now;
        }
    };
    $server = new RconTestServer(clock: $clock);
    $server->setConfig('sv_server_info_replies_per_second', 1);
    $socket  = new RconTestSocket;
    $packet  = new ConnectionlessPacket(NetworkMessages::SERVERBROWSE_GETINFO.chr(42), extraToken: 0);
    $handler = new ReflectionMethod(AbstractServerInstance::class, 'onPacket');
    $query   = fn () => $handler->invoke($server, $packet, ['address' => '192.0.2.1', 'port' => 9000], $socket);
    $query();
    $query();
    expect($socket->packets)->toHaveCount(1);
    $clock->now = 1_000_000_000;
    $query();
    expect($socket->packets)->toHaveCount(2);
    $server->setConfig('sv_server_info_replies_per_second', 0);
    $query();
    $query();
    expect($socket->packets)->toHaveCount(4);
});

test('tournament and welcome settings apply when a player enters', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;
    $world->setConfig('sv_world_join_as_spectator', 1);
    $world->setConfig('sv_world_welcome', 'Welcome, player!');
    $client        = $server->addClient(state: ConnectionSlot::STATE_READY);
    $client->state = ConnectionSlot::STATE_INGAME;
    $world->addTee($client->playerTee());
    $client->chunks()->send();
    expect($client->playerTee()->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($client->playerTee()->spawning)->toBeFalse();
    expect(array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class)))->toContain('Welcome, player!');
});

test('world construction rejects spectator reservations outside its client limit', function (int $spectatorSlots) {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);

    expect(fn () => new TestWorldWithController($clock, new Map, $controller, slotsLimit: 8, spectatorSlots: $spectatorSlots))
        ->toThrow(InvalidArgumentException::class, 'Spectator slots must be between zero and the world client limit');
})->with([-1, 9]);

test('spectator reservations cannot make world player capacity negative', function () {
    $server = new RconTestServer;
    $world  = $server->addWorldFromConfig(new WorldConfig('spectators', 'dm1', 4, 4));
    expect($world->getSpectatorSlots())->toBe(4);
    expect($world->getGameController()->canJoinTeam(GameConstants::TEAM_RED, -1))->toBeFalse();
});

test('disabled kick and spectate votes and kick-vote minimums are enforced', function (string $setting, int $value, string $type) {
    $server = new RconTestServer;
    $caller = $server->addClient();
    $target = $server->addClient(port: 8301);
    $server->entryWorld->setConfig($setting, $value);
    $server->send($caller, new ClCallVoteChunk($type, (string) $target->playerTee()->teeIndex, ''));
    expect($server->entryWorld->getVoteController()->isVoteRunning())->toBeFalse();
})->with([['sv_world_vote_kick', 0, 'kick'], ['sv_world_vote_spectate', 0, 'spectate'], ['sv_world_vote_kick_min_players', 3, 'kick']]);

test('kick-vote ban duration respects zero and converts minutes to seconds', function (int $minutes) {
    $server = new RconTestServer;
    $caller = $server->addClient(address: '192.0.2.1');
    $target = $server->addClient(address: '192.0.2.2');
    $server->entryWorld->setConfig('sv_world_vote_kick_ban_duration', $minutes);
    $server->send($caller, new ClCallVoteChunk('kick', (string) $target->playerTee()->teeIndex, ''));
    $server->send($target, new ClVoteChunk(1));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);
    expect($target->state)->toBe(ConnectionSlot::STATE_CLOSED);
    $ban = $server->getBanList()->getBan('192.0.2.2');
    if ($minutes === 0) {
        expect($ban)->toBeNull();
    } else {
        expect($ban)->not->toBeNull();
        assert($ban !== null);
        expect($ban->expiry)->toBeGreaterThanOrEqual(time() + $minutes * 60 - 1);
    }
})->with([0, 2]);

test('a passed spectate vote applies the configured rejoin delay', function () {
    $server = new RconTestServer;
    $caller = $server->addClient();
    $target = $server->addClient(port: 8301);
    $world  = $server->entryWorld;
    $world->setConfig('sv_world_vote_spectate_rejoin_delay', 2);
    $server->send($caller, new ClCallVoteChunk('spectate', (string) $target->playerTee()->teeIndex, ''));
    $server->send($target, new ClVoteChunk(1));
    $world->getVoteController()->tick($world);
    expect($target->playerTee()->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($target->playerTee()->teamChangeTick)->toBe($world->getCurrentTick() + 120 * NetworkParams::TICKS_PER_SECOND);
    $server->send($target, new ClSetTeamChunk(GameConstants::TEAM_RED));
    expect($target->playerTee()->team)->toBe(GameConstants::TEAM_SPECTATORS);
});

test('configured vote duration controls the advertised timeout and expiry', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $world->setConfig('sv_world_vote_duration', 5);
    $world->getVoteController()->startVote($world, 'Example', 'echo example', '');
    $running = $world->getVoteController()->getRunningVote($world);
    expect($running)->not->toBeNull();
    assert($running instanceof SvVoteSetChunk);
    expect($running->timeout)->toBe(5);
    for ($i = 0; $i <= 5 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->advanceTick();
    }
    $world->getVoteController()->tick($world);
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
});

test('chat delay is configurable and disabling spam protection bypasses the chat throttle', function () {
    $clock  = new TickHandler(100);
    $server = new RconTestServer(clock: $clock);
    $client = $server->addClient();
    $world  = $server->entryWorld;
    $world->setConfig('sv_world_chat_delay', 5);
    $server->send($client, new ClSayChunk(false, 'First'));
    $server->send($client, new ClSayChunk(false, 'Blocked'));
    $texts = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class));
    expect($texts)->toContain('First');
    expect($texts)->not->toContain('Blocked');
    $world->setConfig('sv_world_spam_protection', 0);
    $server->send($client, new ClSayChunk(false, 'Allowed'));
    $texts = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class));
    expect($texts)->toContain('Allowed');
});

test('strict spectator mode hides combat stats but not the owning player stats', function () {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $owner  = new PlayerTee;
    $viewer = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($viewer);
    $viewer->team        = GameConstants::TEAM_SPECTATORS;
    $viewer->spectatorId = $owner->teeIndex;
    $character           = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $owner);
    $world->setConfig('sv_world_hide_stats_from_spectators', 1);
    $snapshot = $character->doSnap($viewer)[0];
    $owned    = $character->doSnap($owner)[0];
    assert($snapshot instanceof ObjCharacterItem && $owned instanceof ObjCharacterItem);
    expect([$snapshot->health, $snapshot->armor, $snapshot->ammoCount])->toBe([0, 0, 0]);
    expect($owned->health)->toBe(10);
});

test('team damage can be enabled without disabling same-team identification', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, config: new Config(['sv_world_game_type' => 'tdm']));
    $attacker   = new PlayerTee;
    $victim     = new PlayerTee;
    $world->addTee($attacker);
    $world->addTee($victim);
    $attacker->team = $victim->team = GameConstants::TEAM_RED;
    $character      = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $victim);
    $character->takeDamage(new Vector2(0, 0), 2, $attacker->teeIndex, GameConstants::WEAPON_GUN);
    expect($character->health)->toBe(10);
    $world->setConfig('sv_world_pvp_team_damage', 1);
    $character->takeDamage(new Vector2(0, 0), 2, $attacker->teeIndex, GameConstants::WEAPON_GUN);
    expect($character->health)->toBe(8);
    expect($controller->isFriendlyFire($victim->teeIndex, $attacker->teeIndex))->toBeTrue();
});

test('map rotation waits for the configured round count and queues a real map reload', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map(__DIR__.'/../dm1.map'), $controller);
    $world->setConfig('sv_world_pvp_map_rotation', 'dm1, ctf1');
    $world->setConfig('sv_world_pvp_rounds_per_map', 2);
    $controller->cycleMap();
    $world->doTick();
    expect($world->getMap()->getName())->toBe('dm1');
    (new ReflectionProperty(PvpGameController::class, 'roundCount'))->setValue($controller, 1);
    $controller->cycleMap();
    $world->doTick();
    expect($world->getMap()->getName())->toBe('ctf1');
    expect($world->getConfigValue('sv_world_map'))->toBe('ctf1');
});

test('unpause countdown waits for the configured number of ticks', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller);
    $world->setConfig('sv_world_pvp_unpause_timer', 2);
    $controller->togglePause();
    $controller->togglePause();
    expect($world->isPaused())->toBeTrue();
    for ($i = 0; $i < 2 * NetworkParams::TICKS_PER_SECOND - 1; $i++) {
        $clock->advanceTick();
        $controller->doTick();
    }
    expect($world->isPaused())->toBeTrue();
    $clock->advanceTick();
    $controller->doTick();
    expect($world->isPaused())->toBeFalse();
});

test('shutdown when empty waits until a client has connected and left', function () {
    $server = new RconTestServer;
    $server->setConfig('sv_shutdown_when_empty', 1);
    $server->tick();
    expect($server->shutdownRequested)->toBeFalse();
    $server->connect('192.0.2.1', 9000);
    $connections = $server->getConnections();
    expect($connections)->toHaveCount(1);
    $connections[0]->closeConnection('Leaving');
    $server->tick();
    expect($server->shutdownRequested)->toBeTrue();
});

test('configuration reloads maps and gametypes through the usual queued reload path', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $world  = $server->entryWorld;
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('sv_world_map ctf1; sv_world_game_type ctf'));
    expect($world->getMap()->getName())->toBe('dm1');
    $world->doTick();
    expect($world->getMap()->getName())->toBe('ctf1');
    expect($world->getGameController()->getGameType())->toBe('CTF');
    expect($admin->state)->toBe(ConnectionSlot::STATE_LOADING);
    expect($admin->rcon()->authenticated)->toBeTrue();
    expect(fn () => $world->setConfig('sv_world_map', 'missing-map'))->toThrow(RuntimeException::class);
    expect($world->getConfigValue('sv_world_map'))->toBe('ctf1');
});

test('configured score limits control win checks as well as snapshots', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, config: new Config(['sv_world_pvp_score_limit' => 20]));
    $tee        = new PlayerTee;
    $world->addTee($tee);
    $tee->score = 5;
    $controller->doWincheck();
    expect($controller->isGameOver())->toBeFalse();
    $world->setConfig('sv_world_pvp_score_limit', 5);
    $controller->doWincheck();
    expect($controller->isGameOver())->toBeTrue();
});

test('rules configured in a file are shown in order by the normal chat command', function () {
    withConfigFiles(['rules.cfg' => "sv_world_rules_line1 \"Be kind.\"\nsv_world_rules_line3 \"No cheating.\""], function (string $directory): void {
        $server = new RconTestServer(configFile: $directory.'/rules.cfg');
        $client = $server->addClient();
        $server->send($client, new ClSayChunk(false, '/rules'));
        expect($server->entryWorld->getRules())->toBe(['Be kind.', 'No cheating.']);
        $lines = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class));
        expect($lines)->toBe(['Be kind.', 'No cheating.']);
    });
});

test('enabling team balancing starts the timer for already unbalanced teams', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, config: new Config(['sv_world_game_type' => 'tdm']));
    $first      = new PlayerTee;
    $second     = new PlayerTee;
    $world->addTee($first);
    $world->addTee($second);
    $first->team = $second->team = GameConstants::TEAM_RED;
    $world->setConfig('sv_world_pvp_team_balance_time', 1);
    for ($i = 0; $i <= 60 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->advanceTick();
    }
    $controller->doTick();
    expect([$first->team, $second->team])->toContain(GameConstants::TEAM_RED, GameConstants::TEAM_BLUE);
});

test('round swapping moves playing teams and leaves spectators alone', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, config: new Config(['sv_world_game_type' => 'tdm']));
    $red        = new PlayerTee;
    $blue       = new PlayerTee;
    $spectator  = new PlayerTee;
    foreach ([$red, $blue, $spectator] as $tee) {
        $world->addTee($tee);
    }
    $red->team       = GameConstants::TEAM_RED;
    $blue->team      = GameConstants::TEAM_BLUE;
    $spectator->team = GameConstants::TEAM_SPECTATORS;
    $world->setConfig('sv_world_pvp_swap_teams_between_rounds', 1);
    $controller->cycleMap();
    expect([$red->team, $blue->team, $spectator->team])->toBe([GameConstants::TEAM_BLUE, GameConstants::TEAM_RED, GameConstants::TEAM_SPECTATORS]);
});

test('inactivity settings can be enabled without replacing the controller', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller);
    $tee        = new PlayerTee;
    $world->addTee($tee);
    $world->setConfig('sv_world_inactivity_timeout', 1);
    $world->setConfig('sv_world_inactivity_action', 2);
    for ($i = 0; $i <= 60 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->advanceTick();
    }
    $controller->doTick();
    expect(recordingServer($world)->kickedTees)->toBe([$tee->teeIndex => 'Kicked for inactivity']);
});

test('appearance change delay is configurable and disabled spam protection bypasses it', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $world->setConfig('sv_world_info_change_delay', 10);
    $world->onMessage($tee, new ClChangeInfoChunk('First', '', -1, 'default', false, 0, 0));
    $world->onMessage($tee, new ClChangeInfoChunk('Blocked', '', -1, 'default', false, 0, 0));
    expect($tee->name)->toBe('First');
    $world->setConfig('sv_world_spam_protection', 0);
    $world->onMessage($tee, new ClChangeInfoChunk('Allowed', '', -1, 'default', false, 0, 0));
    expect($tee->name)->toBe('Allowed');
});

test('team change delay is configurable independently of the team rejoin penalty', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $world->setConfig('sv_world_team_change_delay', 10);
    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));
    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_RED));
    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    $world->setConfig('sv_world_team_change_delay', 0);
    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_RED));
    expect($tee->team)->toBe(GameConstants::TEAM_RED);
});

test('self-kill delay is configurable without changing the respawn penalty', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $tee);
    $tee->lastKillTick = $clock->getCurrentTick();
    $world->onMessage($tee, new ClKillChunk);
    expect($character->alive)->toBeTrue();
    $world->setConfig('sv_world_self_kill_delay', 0);
    $world->onMessage($tee, new ClKillChunk);
    expect($character->alive)->toBeFalse();
    expect($tee->respawnTick)->toBe($clock->getCurrentTick() + 3 * NetworkParams::TICKS_PER_SECOND);
});

test('vote call delay can be changed without bypassing other vote checks', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $tee->lastVoteCall = 90;
    $world->getVoteController()->addVoteOption('Example', 'echo example');
    $world->getVoteController()->callVote($world, $tee, 'option', 'Example', '');
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    $world->setConfig('sv_world_vote_delay', 0);
    $world->setConfig('sv_world_spam_protection', 0);
    $world->getVoteController()->callVote($world, $tee, 'option', 'Example', '');
    expect($world->getVoteController()->isVoteRunning())->toBeTrue();
});

test('forced player votes use configured ban durations and rejoin delays', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient(address: '192.0.2.1');
    $target = $server->addClient(address: '192.0.2.2');
    $world  = $server->entryWorld;
    $world->setConfig('sv_world_vote_spectate_rejoin_delay', 2);
    $world->setConfig('sv_world_vote_kick_ban_duration', 0);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('force_vote spectate 1'));
    expect($target->playerTee()->teamChangeTick)->toBe($world->getCurrentTick() + 120 * NetworkParams::TICKS_PER_SECOND);
    $server->send($admin, new RconCmdChunk('force_vote kick 1'));
    expect($target->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->getBanList()->getBan('192.0.2.2'))->toBeNull();
});

test('zero rcon attempt limit allows repeated failures without banning or disconnecting', function () {
    $server = new RconTestServer(maxTries: 0);
    $client = $server->addClient();
    for ($i = 0; $i < 12; $i++) {
        $server->send($client, new RconAuthChunk('', 'wrong'));
    }
    expect($client->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($server->getBanList()->getBan($client->destinationAddress))->toBeNull();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    expect($client->rcon()->authenticated)->toBeTrue();
});
