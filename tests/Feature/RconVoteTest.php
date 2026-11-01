<?php

use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\Commands\Server\ChangeGameModeCommand;
use TeeFrame\Game\Commands\Server\ChangeMapCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionListAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionRemoveChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\ConnectionSlot;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;
use Tests\Support\RconTestSocket;

require_once __DIR__.'/../Support/RconTestServer.php';

/** @return list<string> */
function rconVoteLines(RconTestSocket $socket): array
{
    return array_map(fn (RconLineChunk $chunk): string => $chunk->line, $socket->chunks(RconLineChunk::class));
}

test('add_vote registers a world option and updates current and newly joining clients', function () {
    $server     = new RconTestServer;
    $world      = $server->entryWorld;
    $otherWorld = $server->addWorld('other');
    $admin      = $server->addClient();
    $guest      = $server->addClient(port: 8301);
    $other      = $server->addClient($otherWorld, port: 8302);
    $world->registerCommand(new ChangeMapCommand);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('add_vote "CTF map" change_map ctf1'));

    expect($world->getVoteController()->getVoteOption('ctf MAP')?->command)->toBe('change_map ctf1');
    expect($otherWorld->getVoteController()->getVoteOption('CTF map'))->toBeNull();
    foreach ([$admin, $guest] as $client) {
        expect($server->socket($client)->chunks(SvVoteOptionAddChunk::class)[0]->description)->toBe('CTF map');
    }
    expect($server->socket($other)->chunks(SvVoteOptionAddChunk::class))->toBeEmpty();
    $menu = $world->getVoteController()->getVoteChunks($guest->playerTee())[0];
    assert($menu instanceof SvVoteOptionListAddChunk);
    expect($menu->descriptions)->toBe(['CTF map']);
});

test('runtime vote menu changes reach ready clients only after their initial menu was sent', function (bool $readyToEnter) {
    $server              = new RconTestServer;
    $admin               = $server->addClient();
    $ready               = $server->addClient(port: 8301, state: ConnectionSlot::STATE_READY);
    $ready->readyToEnter = $readyToEnter;
    $server->entryWorld->registerCommand(new RconTestCommand);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('add_vote "Record action" record'));
    $server->send($admin, new RconCmdChunk('remove_vote "Record action"'));

    expect($server->socket($ready)->chunks(SvVoteOptionAddChunk::class))->toHaveCount($readyToEnter ? 1 : 0);
    expect($server->socket($ready)->chunks(SvVoteOptionRemoveChunk::class))->toHaveCount($readyToEnter ? 1 : 0);
})->with([false, true]);

test('multiple map vote presets share one command registration and execute their own arguments', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $world  = $server->entryWorld;
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($admin, new RconCmdChunk('add_vote "DM map" change_map dm1'));
    $server->send($admin, new RconCmdChunk('add_vote "CTF map" change_map ctf1'));

    expect($world->getVoteController()->getVoteOption('DM map')?->command)->toBe('change_map dm1');
    expect($world->getVoteController()->getVoteOption('CTF map')?->command)->toBe('change_map ctf1');
    foreach (['CTF map' => 'ctf1', 'DM map' => 'dm1'] as $label => $mapName) {
        $server->send($admin, new RconCmdChunk('force_vote option "'.$label.'"'));
        $server->tick();
        expect($world->getMap()->getName())->toBe($mapName);
    }
    expect(array_filter($world->getCommands(), fn (AbstractCommand $command): bool => $command instanceof ChangeMapCommand))->toHaveCount(1);
});

test('argument-bearing vote commands require an invocation with arguments', function (string $name) {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $world  = $server->entryWorld;
    $world->registerCommand(new ChangeMapCommand);
    $world->registerCommand(new ChangeGameModeCommand);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('add_vote "Invalid invocation" '.$name));

    expect($world->getVoteController()->getVoteOption('Invalid invocation'))->toBeNull();
    expect($world->getVoteController()->resolveCommands($world, $name))->toBeNull();
})->with(['change_map', 'change_gamemode']);

test('duplicate add_vote names do not replace options or send duplicate menu entries', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $votes  = $server->entryWorld->getVoteController();
    $server->entryWorld->registerCommand(new RconTestCommand('first'));
    $server->entryWorld->registerCommand(new RconTestCommand('second'));
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($admin, new RconCmdChunk('add_vote "Action" first'));
    $server->socket($admin)->packets = [];

    $server->send($admin, new RconCmdChunk('add_vote "aCTION" second'));

    expect($votes->getVoteOption('Action')?->command)->toBe('first');
    expect($server->socket($admin)->chunks(SvVoteOptionAddChunk::class))->toBeEmpty();
    expect(implode("\n", rconVoteLines($server->socket($admin))))->toContain('already exists');
});

test('remove_vote accepts quoted or unquoted names and broadcasts the original description', function (string $line) {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $guest  = $server->addClient(port: 8301);
    $other  = $server->addWorld('other');
    $votes  = $server->entryWorld->getVoteController();
    $votes->addVoteOption('Record Action', 'record');
    $other->getVoteController()->addVoteOption('Record Action', 'record');
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk($line));

    expect($votes->getVoteOption('Record Action'))->toBeNull();
    expect($votes->getVoteChunks($guest->playerTee()))->toBeEmpty();
    expect($other->getVoteController()->getVoteOption('Record Action'))->not->toBeNull();
    expect($server->socket($guest)->chunks(SvVoteOptionRemoveChunk::class)[0]->description)->toBe('Record Action');
})->with(['remove_vote "record action"', 'REMOVE_VOTE record action']);

test('vote commands preserve quoted arguments and execute registered command sequences through force_vote and passed votes', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $world  = $server->entryWorld;
    $echo   = new RconTestCommand('echo', params: 's[value]');
    $record = new RconTestCommand;
    $world->registerCommand($echo);
    $world->registerCommand($record);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('add_vote "Quoted action" "echo \\"value; # text\\"; record"'));
    expect($world->getVoteController()->getVoteOption('Quoted action')?->command)->toBe('echo "value; # text"; record');
    $server->send($admin, new RconCmdChunk('force_vote option "Quoted action" "Testing; # reason"'));

    expect($echo->executions)->toHaveCount(1);
    expect($echo->executions[0]['arguments'])->toBe(['value; # text']);
    expect($record->executions)->toHaveCount(1);
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    $server->send($admin, new ClCallVoteChunk('option', 'Quoted action', ''));
    $world->getVoteController()->tick($world);
    expect($echo->executions)->toHaveCount(2);
    expect($record->executions)->toHaveCount(2);
    expect($record->executions[1]['tee'])->toBe($admin->playerTee());
});

test('force_vote option executes immediately without replacing a running poll or its caller', function () {
    $server  = new RconTestServer;
    $admin   = $server->addClient();
    $guest   = $server->addClient(port: 8301);
    $world   = $server->entryWorld;
    $ongoing = new RconTestCommand('ongoing');
    $forced  = new RconTestCommand('forced');
    $world->registerCommand($ongoing);
    $world->registerCommand($forced);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Original vote', 'ongoing');
    $votes->addVoteOption('Forced action', 'forced');
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($guest, new ClCallVoteChunk('option', 'Original vote', ''));

    $server->send($admin, new RconCmdChunk('force_vote OPTION "forced ACTION"'));

    expect($forced->executions)->toHaveCount(1);
    expect($forced->executions[0]['tee'])->toBe($admin->playerTee());
    $runningVote = $votes->getRunningVote($world);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('Original vote');
    $server->send($admin, new ClVoteChunk(1));
    $votes->tick($world);
    expect($ongoing->executions)->toHaveCount(1);
    expect($ongoing->executions[0]['tee'])->toBe($guest->playerTee());
});

test('force_vote kick uses world-local IDs and the configured vote-kick ban path', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $target = $server->addClient(address: '127.0.0.2', port: 8301);
    $other  = $server->addClient($server->addWorld('other'), address: '127.0.0.3', port: 8302);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('force_vote kick 1 "Repeated abuse"'));

    expect($target->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($other->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($server->isBanned('127.0.0.2'))->toBeTrue();
    $controls = array_values(array_filter($server->socket($target)->packets, fn ($packet): bool => $packet instanceof ControlMessage));
    expect($controls[0]->getControlMessageExtra())->toBe("Repeated abuse\0");
});

test('force_vote cannot kick the issuing admin or another authenticated admin', function (int $id) {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $other  = $server->addClient(port: 8301);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($other, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('force_vote kick '.$id));

    expect($admin->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($other->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($server->isBanned('127.0.0.1'))->toBeFalse();
})->with([0, 1]);

test('force_vote spectate uses the player team-transition path and reports its reason', function () {
    $server    = new RconTestServer;
    $admin     = $server->addClient();
    $target    = $server->addClient(port: 8301);
    $tee       = $target->playerTee();
    $character = new PvpCharacterEntity($server->entryWorld, new Vector2(100, 100));
    $character->spawn(new Vector2(100, 100), $tee);
    $server->entryWorld->addEntity($character);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('force_vote spectate 1'));

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee->character)->toBeNull();
    expect($character->alive)->toBeFalse();
    $chats = $server->socket($target)->chunks(SvChatChunk::class);
    expect(implode("\n", array_map(fn (SvChatChunk $chunk): string => $chunk->text, $chats)))->toContain('was moved to spectators (No reason given)');
});

test('vote-management commands reject invalid input without changing options or players', function (string $line) {
    $server  = new RconTestServer;
    $admin   = $server->addClient();
    $guest   = $server->addClient(port: 8301);
    $world   = $server->entryWorld;
    $command = new RconTestCommand;
    $world->registerCommand($command);
    $world->registerCommand(new RconTestCommand('chat_only', type: AbstractCommand::TYPE_CHAT));
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->getRcon()->update();
    $server->socket($admin)->packets = [];

    $server->send($admin, new RconCmdChunk($line));

    expect($world->getVoteController()->getVoteChunks($admin->playerTee()))->toBeEmpty();
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBeEmpty();
    expect($guest->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($guest->playerTee()->team)->not->toBe(GameConstants::TEAM_SPECTATORS);
    expect(rconVoteLines($server->socket($admin)))->not->toBeEmpty();
})->with([
    'add_vote',
    'add_vote "" record',
    'add_vote "'.str_repeat('a', 64).'" record',
    'add_vote "Invalid" ""',
    'add_vote "Invalid" unknown',
    'add_vote "Invalid" chat_only',
    'add_vote "Invalid" logout',
    'add_vote "Invalid" "record; cmdlist"',
    'add_vote "Invalid" "record; unknown"',
    'add_vote "Invalid" "record" extra',
    'remove_vote',
    'remove_vote Missing',
    'remove_vote "Missing" extra',
    'force_vote',
    'force_vote yes',
    'force_vote unknown 1',
    'force_vote option Missing',
    'force_vote kick invalid',
    'force_vote kick -1',
    'force_vote kick 99999999999999999999999',
    'force_vote spectate 999',
]);

test('force_vote option rejects stale menu commands and removed options without executing anything', function () {
    $server  = new RconTestServer;
    $admin   = $server->addClient();
    $command = new RconTestCommand;
    $world   = $server->entryWorld;
    $world->registerCommand($command);
    $world->getVoteController()->addVoteOption('Stale', 'record; unknown');
    $world->getVoteController()->addVoteOption('Malformed', 'record; "unclosed');
    $world->getVoteController()->addVoteOption('Removed', 'record');
    $server->send($admin, new RconAuthChunk('', 'test-secret'));

    $server->send($admin, new RconCmdChunk('force_vote option Stale'));
    $server->send($admin, new RconCmdChunk('force_vote option Malformed'));
    $server->send($admin, new RconCmdChunk('remove_vote Removed'));
    $server->send($admin, new RconCmdChunk('force_vote option Removed'));

    expect($command->executions)->toBeEmpty();
    expect(implode("\n", rconVoteLines($server->socket($admin))))->toContain('Unknown vote option: Removed');
});

test('vote-management commands require authentication and cannot be executed from chat', function () {
    $server  = new RconTestServer;
    $admin   = $server->addClient();
    $world   = $server->entryWorld;
    $command = new RconTestCommand;
    $world->registerCommand($command);
    $world->getVoteController()->addVoteOption('Existing', 'record');

    $server->send($admin, new RconCmdChunk('add_vote Hidden record'));
    $server->send($admin, new RconCmdChunk('remove_vote Existing'));
    $server->send($admin, new RconCmdChunk('force_vote option Existing'));
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($admin, new ClSayChunk(false, '/remove_vote Existing'));

    expect($world->getVoteController()->getVoteOption('Existing'))->not->toBeNull();
    expect($world->getVoteController()->getVoteOption('Hidden'))->toBeNull();
    expect($command->executions)->toBeEmpty();
});

test('invalid quoted command arguments and excess arguments are rejected', function (string $line, string $params) {
    expect(fn () => CommandLine::arguments($line, $params))->toThrow(InvalidArgumentException::class);
})->with([
    ['"unclosed', 's[value]'],
    ['"value" extra', 's[value]'],
    ['"value"suffix', 's[value]'],
    ['value', ''],
]);

test('command arguments preserve the unquoted remainder and decode quoted values once', function (string $line, string $params, array $expected) {
    expect(CommandLine::arguments($line, $params))->toBe($expected);
})->with([
    ['"Record action" record', 's[name] r[command]', ['Record action', 'record']],
    ['"Record action" echo "value with spaces"', 's[name] r[command]', ['Record action', 'echo "value with spaces"']],
    ['"Record action" "echo \\"value with spaces\\""', 's[name] r[command]', ['Record action', 'echo "value with spaces"']],
    ['option "Record action" A longer reason', 's[type] s[value] ?r[reason]', ['option', 'Record action', 'A longer reason']],
    ['  "Record action"  ', 's[name]', ['Record action']],
    ['Record  action', 'r[name]', ['Record  action']],
    ['""', 's[value]', ['']],
]);
