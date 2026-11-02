<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Console;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;
use Tests\Support\RconTestSocket;

require_once __DIR__.'/../Support/RconTestServer.php';

class DispatchProbeCommand extends RconTestCommand
{
    /** @var list<string> */
    public array $aliases = [];

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?Closure $output = null): void
    {
        parent::execute($world, $tee, $arguments, $output);
        $this->reply($world, $tee, $this->getDescription().':'.implode(' ', $arguments), $output);
    }
}

/** @return list<string> */
function dispatchChatLines(RconTestSocket $socket): array
{
    return array_map(fn (SvChatChunk $chunk): string => $chunk->text, $socket->chunks(SvChatChunk::class));
}

/** @return list<string> */
function dispatchRconLines(RconTestSocket $socket): array
{
    return array_map(fn (RconLineChunk $chunk): string => $chunk->line, $socket->chunks(RconLineChunk::class));
}

test('latest command registration is shared by chat RCON help and passed votes without replacing registrations', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;
    $client = $server->addClient();
    $type   = AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER;
    $first  = new DispatchProbeCommand('probe', $type, 'First', 'r[text]');
    $last   = new DispatchProbeCommand('PROBE', $type, 'Last', 'r[text]');
    $world->registerCommand($first);
    $world->registerCommand($last);
    expect($world->getCommands())->toContain($first, $last);
    expect($world->findCommand('probe', $type))->toBe($last);
    expect($world->getVoteController()->resolveCommands($world, 'probe hello')[0]['command'] ?? null)->toBe($last);
    expect($server->getRcon()->getCommandInfo($world)['probe']['help'])->toBe('Last');

    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('PrObE hello'));
    expect(dispatchRconLines($server->socket($client)))->toContain('Last:hello');
    expect(dispatchChatLines($server->socket($client)))->toBeEmpty();

    $server->socket($client)->packets = [];
    $server->send($client, new ClSayChunk(false, '/probe hello'));
    expect(dispatchChatLines($server->socket($client)))->toBe(['Last:hello']);
    expect(dispatchRconLines($server->socket($client)))->not->toContain('Last:hello');

    $client->playerTee()->lastChatTick = 0;
    $server->socket($client)->packets  = [];
    $server->send($client, new ClSayChunk(false, '/help probe'));
    expect(dispatchChatLines($server->socket($client)))->toBe(['/PROBE r[text] - Last']);

    $world->getVoteController()->addVoteOption('Probe', 'probe hello');
    $server->socket($client)->packets = [];
    $server->send($client, new ClCallVoteChunk('option', 'Probe', ''));
    $world->getVoteController()->tick($world);
    expect(dispatchChatLines($server->socket($client)))->toContain('Vote passed')->not()->toContain('Last:hello');
    expect(dispatchRconLines($server->socket($client)))->toContain('[INFO] [vote]: World=dm Last:hello');
    expect($last->executions)->toHaveCount(3);
    expect($first->executions)->toBeEmpty();
});

test('canonical names take precedence over aliases regardless of registration order', function (bool $canonicalLast) {
    $server         = new RconTestServer;
    $world          = $server->entryWorld;
    $client         = $server->addClient();
    $type           = AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER;
    $canonical      = new DispatchProbeCommand('pause_game', $type, 'Canonical');
    $alias          = new DispatchProbeCommand('alias_probe', $type, 'Alias', 'i[value]');
    $alias->aliases = ['PAUSE_GAME'];
    foreach ($canonicalLast ? [$alias, $canonical] : [$canonical, $alias] as $command) {
        $world->registerCommand($command);
    }
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('pause_game; help pause_game'));
    $server->send($client, new ClSayChunk(false, '/pause_game'));

    expect($world->findCommand('pause_game', $type))->toBe($canonical);
    expect($server->getRcon()->getCommandInfo($world)['pause_game'])->toBe(['help' => 'Canonical', 'params' => '']);
    expect($world->getVoteController()->resolveCommands($world, 'pause_game')[0]['command'] ?? null)->toBe($canonical);
    expect(dispatchRconLines($server->socket($client)))->toContain('Canonical:', 'pause_game - Canonical');
    expect(dispatchChatLines($server->socket($client)))->toBe(['Canonical:']);
    expect($canonical->executions)->toHaveCount(2);
    expect($alias->executions)->toBeEmpty();
    expect($world->isPaused())->toBeFalse();
})->with([false, true]);

test('aliases use the latest active registration and replaced names do not retain old aliases', function () {
    $server          = new RconTestServer;
    $world           = $server->entryWorld;
    $type            = AbstractCommand::TYPE_SERVER;
    $first           = new DispatchProbeCommand('probe', $type, 'First');
    $first->aliases  = ['old_alias', 'shared_alias'];
    $other           = new DispatchProbeCommand('other', $type, 'Other');
    $other->aliases  = ['shared_alias'];
    $latest          = new DispatchProbeCommand('probe', $type, 'Latest');
    $latest->aliases = ['new_alias', 'shared_alias'];
    foreach ([$first, $other, $latest] as $command) {
        $world->registerCommand($command);
    }
    expect($world->findCommand('old_alias', $type))->toBeNull();
    expect($world->findCommand('new_alias', $type))->toBe($latest);
    expect($world->findCommand('SHARED_ALIAS', $type))->toBe($latest);
    expect($server->getRcon()->getCommandInfo($world)['shared_alias']['help'])->toBe($latest->getHelpText());
    expect($world->getVoteController()->resolveCommands($world, 'shared_alias')[0]['command'] ?? null)->toBe($latest);
    expect($world->getCommands())->toContain($first, $other, $latest);
});

test('command lookup remains world scoped type scoped and reflects live metadata changes', function () {
    $server        = new RconTestServer;
    $world         = $server->entryWorld;
    $other         = $server->addWorld('other');
    $serverCommand = new DispatchProbeCommand('probe');
    $chatCommand   = new DispatchProbeCommand('probe', AbstractCommand::TYPE_CHAT);
    $world->registerCommand($serverCommand);
    $world->registerCommand($chatCommand);
    expect($world->findCommand('probe', AbstractCommand::TYPE_CHAT))->toBe($chatCommand);
    expect($world->findCommand('probe', AbstractCommand::TYPE_SERVER))->toBe($serverCommand);
    expect($other->findCommand('probe', AbstractCommand::TYPE_SERVER))->toBeNull();
    $chatCommand->type = AbstractCommand::TYPE_SERVER;
    expect($world->findCommand('probe', AbstractCommand::TYPE_SERVER))->toBe($chatCommand);
    expect($world->findCommand('probe', AbstractCommand::TYPE_CHAT))->toBeNull();
    $chatCommand->aliases = ['live_alias'];
    expect($world->findCommand('LIVE_ALIAS', AbstractCommand::TYPE_SERVER))->toBe($chatCommand);
});

test('invalid arguments do not fall back to an older registration with different parameters', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;
    $client = $server->addClient();
    $type   = AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER;
    $first  = new DispatchProbeCommand('probe', $type, 'First', 'r[text]');
    $latest = new DispatchProbeCommand('probe', $type, 'Latest', 'i[number]');
    $world->registerCommand($first);
    $world->registerCommand($latest);
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('probe invalid'));
    $server->send($client, new ClSayChunk(false, '/probe invalid'));
    expect(dispatchRconLines($server->socket($client)))->toContain('Expected an integer for number');
    expect(dispatchChatLines($server->socket($client)))->toBe(['Usage: /probe i[number]']);
    expect($world->getVoteController()->resolveCommands($world, 'probe invalid'))->toBeNull();
    expect($first->executions)->toBeEmpty();
    expect($latest->executions)->toBeEmpty();
});

test('RCON-only builtins retain precedence over registered names and aliases including invalid invocations', function () {
    $server               = new RconTestServer;
    $world                = $server->entryWorld;
    $client               = $server->addClient();
    $replacement          = new DispatchProbeCommand('cmdlist', description: 'Replacement');
    $replacement->aliases = ['logout'];
    $world->registerCommand($replacement);
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->send($client, new RconCmdChunk('cmdlist extra'));
    expect(dispatchRconLines($server->socket($client)))->toContain('Too many command arguments');
    $server->send($client, new RconCmdChunk('help cmdlist'));
    expect(dispatchRconLines($server->socket($client)))->toContain('cmdlist - List available RCON commands');
    $server->send($client, new RconCmdChunk('logout'));
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($replacement->executions)->toBeEmpty();
});

test('RCON replies work for dual-type commands before a player enters the world', function (int $state) {
    $server = new RconTestServer;
    $client = $server->addClient(state: $state);
    $server->entryWorld->registerCommand(new DispatchProbeCommand('probe', AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER, 'Reply'));
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('probe'));
    expect(dispatchRconLines($server->socket($client)))->toContain('Reply:');
    expect(dispatchChatLines($server->socket($client)))->toBeEmpty();
})->with([ConnectionSlot::STATE_LOADING, ConnectionSlot::STATE_READY]);

test('a forced option uses RCON output while a passed poll logs replies to the console', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $world  = $server->entryWorld;
    $world->registerCommand(new DispatchProbeCommand('probe', AbstractCommand::TYPE_SERVER, 'Reply'));
    $world->getVoteController()->addVoteOption('Probe', 'probe');
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('force_vote option Probe'));
    expect(dispatchRconLines($server->socket($client)))->toContain('Reply:');
    expect(dispatchChatLines($server->socket($client)))->not->toContain('Reply:');
    $server->socket($client)->packets = [];
    $server->send($client, new ClCallVoteChunk('option', 'Probe', ''));
    $world->getVoteController()->tick($world);
    expect(dispatchChatLines($server->socket($client)))->toContain('Vote passed')->not()->toContain('Reply:');
    expect(dispatchRconLines($server->socket($client)))->toContain('[INFO] [vote]: World=dm Reply:');
});

test('passed configuration votes log each reply without sending it to player chat', function () {
    $server = new RconTestServer;
    $caller = $server->addClient();
    $other  = $server->addClient(port: 8301);
    $world  = $server->entryWorld;
    $votes  = $world->getVoteController();
    $votes->addVoteOption('CTF', 'sv_world_map ctf1; sv_world_game_type ctf; sv_world_pvp_score_limit 300');
    $server->send($caller, new ClCallVoteChunk('option', 'CTF', ''));
    $server->send($other, new ClVoteChunk(1));
    foreach ([$caller, $other] as $client) {
        $server->socket($client)->packets = [];
    }

    $logs     = [];
    $listener = function (string $line) use (&$logs): void {
        $logs[] = $line;
    };
    Console::listen($listener);
    try {
        $votes->tick($world);
    } finally {
        Console::unlisten($listener);
    }

    expect($votes->isVoteRunning())->toBeFalse();
    expect($world->getConfigValue('sv_world_map'))->toBe('ctf1');
    expect($world->getConfigValue('sv_world_game_type'))->toBe('ctf');
    expect($world->getConfigValue('sv_world_pvp_score_limit'))->toBe(300);
    expect($logs)->toContain(
        '[INFO] [vote]: World=dm sv_world_map = ctf1',
        '[INFO] [vote]: World=dm sv_world_game_type = ctf',
        '[INFO] [vote]: World=dm sv_world_pvp_score_limit = 300',
    );
    foreach ([$caller, $other] as $client) {
        expect(dispatchChatLines($server->socket($client)))->toBe(['Vote passed']);
    }
});

test('interleaved executions of one command keep their reply callbacks isolated', function () {
    $server  = new RconTestServer;
    $first   = $server->addClient(port: 8300);
    $second  = $server->addClient(port: 8301);
    $command = new DispatchProbeCommand('probe', description: 'Reply', params: 'r[text]', handler: static function (): void {
        Fiber::suspend();
    });
    $server->entryWorld->registerCommand($command);
    foreach ([$first, $second] as $client) {
        $server->send($client, new RconAuthChunk('', 'test-secret', false));
        $server->socket($client)->packets = [];
    }
    $firstCall  = new Fiber(fn () => $server->send($first, new RconCmdChunk('probe first')));
    $secondCall = new Fiber(fn () => $server->send($second, new RconCmdChunk('probe second')));
    $firstCall->start();
    $secondCall->start();
    $secondCall->resume();
    $firstCall->resume();
    expect(dispatchRconLines($server->socket($first)))->toContain('Reply:first');
    expect(dispatchRconLines($server->socket($first)))->not->toContain('Reply:second');
    expect(dispatchRconLines($server->socket($second)))->toContain('Reply:second');
    expect(dispatchRconLines($server->socket($second)))->not->toContain('Reply:first');
    expect($command->executions)->toHaveCount(2);
});

test('player-to-IP resolution is world scoped and normalizes both literals and connected addresses', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;
    $server->addClient(address: '2001:0DB8:0:0:0:0:0:1');
    $other = $server->addWorld('other');
    $server->addClient($other, '192.0.2.2');
    $server->addClient($other, '192.0.2.3', 8301);
    expect($server->resolveClientAddress($world, '0'))->toBe('2001:db8::1');
    expect($server->resolveClientAddress($other, '0'))->toBe('192.0.2.2');
    expect($server->resolveClientAddress($world, '2001:0DB8:0:0:0:0:0:1'))->toBe('2001:db8::1');
    foreach (['1', '-1', 'localhost', '192.0.2.1:8303'] as $target) {
        expect(fn () => $server->resolveClientAddress($world, $target))->toThrow(InvalidArgumentException::class);
    }
});
