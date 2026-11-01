<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconAuthStatusChunk;
use TeeFrame\Network\Chunks\System\RconCmdAddChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconCmdRemChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Console;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;
use Tests\Support\RconTestSocket;

require_once __DIR__.'/../Support/RconTestServer.php';

/** @return list<string> */
function rconLines(RconTestSocket $socket): array
{
    return array_map(fn (RconLineChunk $chunk): string => $chunk->line, $socket->chunks(RconLineChunk::class));
}

/** @return list<string> */
function rconCommandNames(RconTestSocket $socket): array
{
    return array_map(fn (RconCmdAddChunk $chunk): string => $chunk->name, $socket->chunks(RconCmdAddChunk::class));
}

/** @return list<string> */
function rconListedNames(RconTestSocket $socket): array
{
    $lines = array_filter(rconLines($socket), fn (string $line): bool => ! str_starts_with($line, 'World=') && ! str_starts_with($line, 'Authentication successful.'));

    return $lines === [] ? [] : explode(', ', implode(', ', $lines));
}

/** Finish the tick-limited autocomplete before testing later command changes. */
function finishRconAutocomplete(RconTestServer $server): void
{
    foreach ($server->entryWorld->getCommands() as $_) {
        $server->getRcon()->update();
    }
}

test('cmdlist lists only sorted RCON command names and aliases from the current world', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $other  = $server->addWorld('other');
    $other->registerCommand(new RconTestCommand('other_world_only'));

    $server->entryWorld->registerCommand(new RconTestCommand('echo', type: AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER, description: 'Echo a value', params: 's[value]'));
    $server->entryWorld->registerCommand(new RconTestCommand('chat_only', type: AbstractCommand::TYPE_CHAT));
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->socket($client)->packets = [];

    $server->send($client, new RconCmdChunk('cmdlist'));

    $lines    = rconLines($server->socket($client));
    $expected = array_keys($server->getRcon()->getCommandInfo($server->entryWorld));
    sort($expected, SORT_STRING | SORT_FLAG_CASE);
    expect(rconListedNames($server->socket($client)))->toBe($expected);
    expect(implode("\n", $lines))->not->toContain('Available RCON commands:', 's[value]', 'Echo a value', ' - ');
    expect(implode("\n", $lines))->not->toContain('other_world_only', 'chat_only', 'whisper', 'ping');
});

test('built-in RCON commands require authentication and cannot run through chat or votes', function (string $command) {
    $server = new RconTestServer;
    $client = $server->addClient();

    $server->send($client, new RconCmdChunk($command));
    $server->send($client, new ClSayChunk(false, '/'.$command));
    $server->send($client, new ClCallVoteChunk('option', $command, ''));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);

    expect(rconLines($server->socket($client)))->toBeEmpty();
    expect($server->entryWorld->getVoteController()->isVoteRunning())->toBeFalse();

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();
    $server->socket($client)->packets = [];
    $server->send($client, (new RconCmdChunk($command))->setFlags(0));
    $server->send($client, new ClSayChunk(false, '/'.$command));
    $server->send($client, new ClCallVoteChunk('option', $command, ''));
    expect(rconLines($server->socket($client)))->toBeEmpty();
    expect($client->rcon()->authenticated)->toBeTrue();
    expect($server->entryWorld->getVoteController()->isVoteRunning())->toBeFalse();
})->with(['cmdlist', 'logout', 'add_vote', 'remove_vote', 'force_vote']);

test('cmdlist is available without registered server commands and ignores command casing', function (string $line) {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->socket($client)->packets = [];

    $server->send($client, new RconCmdChunk($line));

    expect(rconListedNames($server->socket($client)))->toContain('cmdlist');
})->with(['cmdlist', 'CMDLIST']);

test('cmdlist returns complete command lists across multiple reply lines only to the requester', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $other  = $server->addClient(port: 8301);
    foreach (range(1, 20) as $i) {
        $server->entryWorld->registerCommand(new RconTestCommand('command'.$i, description: str_repeat('Description ', 8)));
    }
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($other, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();
    $server->socket($admin)->packets = [];
    $server->socket($other)->packets = [];

    $server->send($admin, new RconCmdChunk('cmdlist'));
    $server->getRcon()->update();

    $lines    = rconLines($server->socket($admin));
    $expected = array_keys($server->getRcon()->getCommandInfo($server->entryWorld));
    sort($expected, SORT_STRING | SORT_FLAG_CASE);
    expect(rconListedNames($server->socket($admin)))->toBe($expected);
    $listLines = array_filter($lines, fn (string $line): bool => ! str_starts_with($line, 'World='));
    expect(count($listLines))->toBeGreaterThan(1);
    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(511);
    }
    expect(rconListedNames($server->socket($other)))->toBeEmpty();
});

test('cmdlist follows world changes without losing the built-in command', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $other  = $server->addWorld('other');
    $server->entryWorld->registerCommand(new RconTestCommand('first'));
    $other->registerCommand(new RconTestCommand('second'));
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('cmdlist'));
    expect(rconListedNames($server->socket($client)))->toContain('first');
    expect($server->changeWorld($client->playerTee(), $other))->toBeTrue();
    $server->socket($client)->packets = [];
    $server->send($client, new RconCmdChunk('cmdlist'));

    expect(rconListedNames($server->socket($client)))->toContain('cmdlist', 'second');
    expect(rconListedNames($server->socket($client)))->not->toContain('first');
});

test('registered commands cannot replace the built-in RCON cmdlist', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand('cmdlist', description: 'Not the built-in');
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();
    $server->getRcon()->update();

    $server->send($client, new RconCmdChunk('cmdlist'));

    expect($command->executions)->toBeEmpty();
    expect(rconCommandNames($server->socket($client)))->toBe(['cmdlist', 'logout', 'add_vote', 'remove_vote', 'force_vote', 'change_map', 'change_gamemode', 'reload']);
    expect($server->socket($client)->chunks(RconCmdAddChunk::class)[0]->help)->toBe('List available RCON commands');
    expect(rconListedNames($server->socket($client)))->toContain('cmdlist');
});

test('RCON chunks use the 0.6 message IDs and field order', function (AbstractChunk $chunk, int $message, array $payload) {
    $chunk->setSequence(1);
    $decoded = PacketDecoder::decodeChunksFromPayload($chunk->encode())[0];

    expect($chunk->isSystem())->toBeTrue();
    expect($chunk->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL);
    expect($chunk->encode()[3])->toBe(($message << 1) | 1);
    expect($chunk->getPayload()->encode())->toBe($payload);
    expect($decoded)->toBeInstanceOf($chunk::class);
    expect($decoded->getPayload()->encode())->toBe($payload);
})->with([
    'authentication' => [new RconAuthChunk('', 'pw', true), 18, [0, 112, 119, 0, 1]],
    'command'        => [new RconCmdChunk('record'), 17, [114, 101, 99, 111, 114, 100, 0]],
    'auth status'    => [new RconAuthStatusChunk(true, false), 10, [1, 0]],
    'line'           => [new RconLineChunk('ok'), 11, [111, 107, 0]],
    'command add'    => [new RconCmdAddChunk('x', 'h', 'p'), 25, [120, 0, 104, 0, 112, 0]],
    'command remove' => [new RconCmdRemChunk('x'), 26, [120, 0]],
]);

test('malformed RCON fields reject the whole packet', function (int $message, array $fields) {
    $size = count($fields) + 1;
    $raw  = NetworkBase::packBuffer([0, 0, 1, 0x40 | ($size >> 4), $size & 15, 1, ($message << 1) | 1, ...$fields]);

    expect(PacketDecoder::decodeFromRaw($raw))->toBeFalse();
})->with([
    'missing auth name'             => [18, []],
    'missing auth password'         => [18, [0]],
    'missing command list flag'     => [18, [0, 0]],
    'truncated command list flag'   => [18, [0, 0, 0x80]],
    'unterminated password'         => [18, [0, 112]],
    'unterminated command'          => [17, [115]],
    'missing auth status list flag' => [10, [1]],
    'unterminated output'           => [11, [111]],
    'missing command help'          => [25, [120, 0]],
    'missing command params'        => [25, [120, 0, 104, 0]],
    'unterminated removed command'  => [26, [120]],
]);

test('RCON output respects client byte limits without cutting UTF-8 characters', function () {
    $line    = RconLineChunk::make((new RconLineChunk(str_repeat('é', 300)))->getPayload());
    $command = RconCmdAddChunk::make((new RconCmdAddChunk(str_repeat('é', 64), str_repeat('é', 192), str_repeat('é', 96)))->getPayload());
    $removed = RconCmdRemChunk::make((new RconCmdRemChunk(str_repeat('é', 64)))->getPayload());

    expect(strlen($line->line))->toBe(510);
    expect(strlen($command->name))->toBe(62);
    expect(strlen($command->help))->toBe(190);
    expect(strlen($command->params))->toBe(94);
    expect(strlen($removed->name))->toBe(62);
    expect(mb_check_encoding($line->line.$command->name.$command->help.$command->params.$removed->name, 'UTF-8'))->toBeTrue();
    expect(RconLineChunk::make((new RconLineChunk("before\0after"))->getPayload())->line)->toBe('before');
});

test('RCON is disabled without a configured password, including an empty login', function () {
    $server  = new RconTestServer(password: '');
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);

    $server->send($client, new RconAuthChunk('', ''));
    $server->send($client, new RconCmdChunk('record'));

    expect($client->rcon()->authenticated)->toBeFalse();
    expect($client->rcon()->authTries)->toBe(0);
    expect($command->executions)->toBeEmpty();
    expect(rconLines($server->socket($client)))->toBe(['Remote console is disabled.']);
    expect($server->socket($client)->chunks(RconAuthStatusChunk::class))->toBeEmpty();
});

test('only the configured RCON password grants admin access', function () {
    $server = new RconTestServer;
    $client = $server->addClient();

    $server->send($client, new RconAuthChunk('', 'wrong'));
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($client->rcon()->authTries)->toBe(1);
    expect(rconLines($server->socket($client)))->toBe(['Wrong password 1/3.']);

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    expect($client->rcon()->authenticated)->toBeTrue();
    expect($client->rcon()->authTries)->toBe(0);
    $status = $server->socket($client)->chunks(RconAuthStatusChunk::class);
    expect($status)->toHaveCount(1);
    expect($status[0]->authenticated)->toBeTrue();
    expect($status[0]->commandList)->toBeTrue();
});

test('RCON can authenticate while loading and ready but not before version and server password validation', function (int $state, bool $expected) {
    $server  = new RconTestServer;
    $client  = $server->addClient(state: $state);
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->send($client, new RconCmdChunk('record'));

    expect($client->rcon()->authenticated)->toBe($expected);
    expect(count($command->executions))->toBe($expected ? 1 : 0);
})->with([
    'connecting' => [ConnectionSlot::STATE_CONNECTING, false],
    'loading'    => [ConnectionSlot::STATE_LOADING, true],
    'ready'      => [ConnectionSlot::STATE_READY, true],
    'in game'    => [ConnectionSlot::STATE_INGAME, true],
]);

test('RCON auth in the same packet as valid connection info is processed once', function () {
    $server = new RconTestServer;
    $client = $server->addClient(state: ConnectionSlot::STATE_CONNECTING);

    $server->send($client, new InfoChunk('0.6 626fce9a778df4d4'), new RconAuthChunk('', 'test-secret'));

    expect($client->state)->toBe(ConnectionSlot::STATE_LOADING);
    expect($client->rcon()->authenticated)->toBeTrue();
    expect($server->socket($client)->chunks(RconAuthStatusChunk::class))->toHaveCount(1);
});

test('non-vital RCON messages cannot authenticate or execute commands', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);

    $server->send($client, (new RconAuthChunk('', 'test-secret'))->setFlags(0));
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($server->socket($client)->packets)->toBeEmpty();

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->send($client, (new RconCmdChunk('record'))->setFlags(0));
    expect($command->executions)->toBeEmpty();
});

test('server commands require RCON authentication and cannot run through chat', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);

    $server->send($client, new RconCmdChunk('record'));
    $server->send($client, new ClSayChunk(false, '/record'));
    expect($command->executions)->toBeEmpty();

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->send($client, new RconCmdChunk('record'));
    expect($command->executions)->toHaveCount(1);
});

test('chat-only commands cannot run through RCON or an option vote', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand(type: AbstractCommand::TYPE_CHAT);
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('record'));
    $server->send($client, new ClCallVoteChunk('option', 'record', ''));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);
    $server->getRcon()->update();
    $server->getRcon()->update();
    expect($command->executions)->toBeEmpty();
    expect(rconCommandNames($server->socket($client)))->toBe(['cmdlist', 'logout', 'add_vote', 'remove_vote', 'force_vote', 'change_map', 'change_gamemode', 'reload']);

    $server->send($client, new ClSayChunk(false, '/record'));
    expect($command->executions)->toHaveCount(1);
});

test('server commands use the same instance and implementation for RCON and passed votes', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->entryWorld->getVoteController()->addVoteOption('Record action', $command->getCommand());
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('record'));
    $server->send($client, new ClCallVoteChunk('option', 'Record action', ''));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);

    expect($command->executions)->toHaveCount(2);
    expect($command->executions[0])->toBe(['world' => $server->entryWorld, 'tee' => $client->playerTee(), 'arguments' => []]);
    expect($command->executions[1])->toBe(['world' => $server->entryWorld, 'tee' => $client->playerTee(), 'arguments' => []]);
});

test('commands can explicitly support both chat and server execution', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand(type: AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER);
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new ClSayChunk(false, '/record'));
    $server->send($client, new RconCmdChunk('record'));

    expect($command->executions)->toHaveCount(2);
});

test('exhausting RCON login attempts closes the connection and blocks reconnects', function () {
    $server = new RconTestServer;
    $client = $server->addClient();

    $server->send($client, new RconAuthChunk('', 'wrong'));
    $server->send($client, new RconAuthChunk('', 'wrong'));
    $server->send($client, new RconAuthChunk('', 'wrong'), new RconAuthChunk('', 'test-secret'));

    expect($client->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($server->isBanned('127.0.0.1'))->toBeTrue();
    $reply = $server->connect('127.0.0.1', 8301)->packets[0];
    expect($reply)->toBeInstanceOf(ControlMessage::class);
    assert($reply instanceof ControlMessage);
    expect($reply->getControlMessageExtra())->toContain('You are banned');
});

test('a zero RCON ban duration disconnects without banning', function () {
    $server = new RconTestServer(maxTries: 1, banDuration: 0);
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'wrong'));

    expect($client->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->isBanned('127.0.0.1'))->toBeFalse();
});

test('replayed vital RCON commands execute only once', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $packet = (new RconCmdChunk('record'))->setSequence($client->ack + 1);

    $server->send($client, $packet);
    $server->send($client, $packet);

    expect($command->executions)->toHaveCount(1);
});

test('RCON autocomplete incrementally advertises built-ins and registered server commands with their metadata', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    foreach (range(1, 5) as $i) {
        $server->entryWorld->registerCommand(new RconTestCommand('command'.$i, description: 'Description '.$i, params: 's[value]'));
    }
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    expect(rconCommandNames($server->socket($client)))->toBeEmpty();

    $server->getRcon()->update();
    expect(rconCommandNames($server->socket($client)))->toBe(['cmdlist', 'logout', 'add_vote', 'remove_vote']);
    $server->getRcon()->update();
    expect(rconCommandNames($server->socket($client)))->toBe(['cmdlist', 'logout', 'add_vote', 'remove_vote', 'force_vote', 'change_map', 'change_gamemode', 'reload']);
    $server->getRcon()->update();
    expect(rconCommandNames($server->socket($client)))->toHaveCount(12);
    finishRconAutocomplete($server);
    $first = array_find($server->socket($client)->chunks(RconCmdAddChunk::class), fn (RconCmdAddChunk $chunk): bool => $chunk->name === 'command1');
    assert($first instanceof RconCmdAddChunk);
    expect($first->help)->toBe('Description 1');
    expect($first->params)->toBe('s[value]');
    expect(rconCommandNames($server->socket($client)))->toBe(array_keys($server->getRcon()->getCommandInfo($server->entryWorld)));
});

test('RCON honors clients that do not request autocomplete', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret', false));
    $server->getRcon()->update();

    expect($server->socket($client)->chunks(RconAuthStatusChunk::class)[0]->commandList)->toBeFalse();
    expect(rconCommandNames($server->socket($client)))->toBeEmpty();
    expect($client->rcon()->advertisedCommands)->toBeEmpty();
    $server->send($client, new RconCmdChunk('record'));
    expect($command->executions)->toHaveCount(1);
});

test('registering a world command after RCON authentication updates execution and autocomplete', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    finishRconAutocomplete($server);
    $server->socket($client)->packets = [];

    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->getRcon()->update();
    expect(rconCommandNames($server->socket($client)))->toBe(['record']);
    $server->send($client, new RconCmdChunk('record'));
    expect($command->executions)->toHaveCount(1);
});

test('RCON autocomplete refreshes when command permissions or parameter hints change', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    finishRconAutocomplete($server);
    $server->socket($client)->packets = [];

    $command->params = 's[new_value]';
    $server->getRcon()->update();
    expect($server->socket($client)->chunks(RconCmdRemChunk::class)[0]->name)->toBe('record');
    expect($server->socket($client)->chunks(RconCmdAddChunk::class)[0]->params)->toBe('s[new_value]');
    $server->socket($client)->packets = [];
    $command->type                    = AbstractCommand::TYPE_CHAT;
    $server->getRcon()->update();
    expect($server->socket($client)->chunks(RconCmdRemChunk::class)[0]->name)->toBe('record');
    expect(rconCommandNames($server->socket($client)))->toBeEmpty();
});

test('RCON leaves unsupported recording and file-execution commands unavailable', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    finishRconAutocomplete($server);
    $server->send($client, new RconCmdChunk('record; stoprecord; exec autoexec.cfg'));

    expect(rconCommandNames($server->socket($client)))->not->toContain('record', 'stoprecord', 'exec');
    expect($server->shutdownRequested)->toBeFalse();
    expect($client->rcon()->authenticated)->toBeTrue();
    expect(rconLines($server->socket($client)))->toContain('Unknown command: record', 'Unknown command: stoprecord', 'Unknown command: exec');
});

test('RCON forwards console output only to authenticated clients', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $guest  = $server->addClient(port: 8301);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets = [];
    $server->socket($guest)->packets = [];

    Console::info('RCON broadcast test');
    Console::warn('RCON warning test');
    $server->getRcon()->update();

    expect(rconLines($server->socket($admin)))->toContain('RCON broadcast test', 'RCON warning test');
    expect(rconLines($server->socket($guest)))->toBeEmpty();
});

test('RCON never logs passwords or command arguments', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->entryWorld->registerCommand(new RconTestCommand('secret', params: 'r[value]'));

    ob_start();
    try {
        $server->send($client, new RconAuthChunk('', 'wrong-secret'));
        $server->send($client, new RconAuthChunk('', 'test-secret'));
        $server->send($client, new RconCmdChunk('secret private-argument'));
    } finally {
        $output = ob_get_clean();
    }

    expect($output)->not->toContain('test-secret', 'wrong-secret', 'private-argument');
    expect($output)->toContain('RCON command=secret');
});

test('built-in logout revokes access and stops later commands in the same line', function (string $logout) {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();

    $server->send($client, new RconCmdChunk($logout.'; record'));
    $server->send($client, new RconCmdChunk('record'));

    expect($command->executions)->toBeEmpty();
    expect($client->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($client->rcon()->advertisedCommands)->toBeEmpty();
    expect(rconLines($server->socket($client)))->toContain('Logged out.');
    $status = $server->socket($client)->chunks(RconAuthStatusChunk::class);
    expect($status)->toHaveCount(2);
    expect($status[1]->authenticated)->toBeFalse();
    expect($status[1]->commandList)->toBeFalse();

    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->send($client, new RconCmdChunk('record'));
    expect($command->executions)->toHaveCount(1);
})->with(['logout', 'LOGOUT']);

test('logout affects only the requesting administrator and cannot be overridden by a registered command', function () {
    $server  = new RconTestServer;
    $admin   = $server->addClient();
    $other   = $server->addClient(port: 8301);
    $command = new RconTestCommand('logout', description: 'Not the built-in');
    $server->entryWorld->registerCommand($command);
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($other, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();
    expect($server->socket($admin)->chunks(RconCmdAddChunk::class)[1]->help)->toBe('Log out of the remote console');

    $server->send($admin, new RconCmdChunk('logout'));

    expect($command->executions)->toBeEmpty();
    expect($admin->rcon()->authenticated)->toBeFalse();
    expect($other->rcon()->authenticated)->toBeTrue();
    $server->send($other, new RconCmdChunk('cmdlist'));
    expect(rconListedNames($server->socket($other)))->toContain('logout');
    expect(rconLines($server->socket($other)))->not->toContain('Logged out.');
});

test('RCON execution and autocomplete follow the current world across world changes', function () {
    $server = new RconTestServer;
    $other  = $server->addWorld('other');
    $client = $server->addClient();
    $first  = new RconTestCommand('first');
    $second = new RconTestCommand('second');
    $server->entryWorld->registerCommand($first);
    $other->registerCommand($second);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    finishRconAutocomplete($server);
    expect(rconCommandNames($server->socket($client)))->toBe(array_keys($server->getRcon()->getCommandInfo($server->entryWorld)));
    $server->send($client, new RconCmdChunk('second'));
    expect($second->executions)->toBeEmpty();
    $server->socket($client)->packets = [];

    expect($server->changeWorld($client->playerTee(), $other))->toBeTrue();
    $server->getRcon()->update();
    expect($client->rcon()->authenticated)->toBeTrue();
    expect($server->socket($client)->chunks(RconCmdRemChunk::class)[0]->name)->toBe('first');
    expect(rconCommandNames($server->socket($client)))->toBe(['second']);
    $server->send($client, new RconCmdChunk('first; second'));
    expect($first->executions)->toBeEmpty();
    expect($second->executions)->toHaveCount(1);
    expect($second->executions[0]['world'])->toBe($other);
});

test('default map and gamemode commands expose generic autocomplete and accept runtime arguments', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $server->getRcon()->update();
    $server->getRcon()->update();

    expect(rconCommandNames($server->socket($client)))->toBe(['cmdlist', 'logout', 'add_vote', 'remove_vote', 'force_vote', 'change_map', 'change_gamemode', 'reload']);
    $mapInfo = array_find($server->socket($client)->chunks(RconCmdAddChunk::class), fn (RconCmdAddChunk $chunk): bool => $chunk->name === 'change_map');
    assert($mapInfo instanceof RconCmdAddChunk);
    expect($mapInfo->params)->toBe('s[name] ?r[reason]');

    foreach (['ctf1', 'dm1'] as $mapName) {
        $server->send($client, new RconCmdChunk('change_map "'.$mapName.'"'));
        $server->tick();
        expect($server->entryWorld->getMap()->getName())->toBe($mapName);
    }
    foreach (['ctf', 'tdm', 'dm'] as $mode) {
        $server->send($client, new RconCmdChunk('change_gamemode "'.$mode.'"'.($mode === 'ctf' ? ' ctf1' : '')));
        $server->tick();
        expect($server->entryWorld->getGameController()->getGameType())->toBe(strtoupper($mode));
    }

});

test('parameterized command registrations do not create callable vote presets', function (string $value) {
    $server = new RconTestServer;
    $client = $server->addClient();
    $votes  = $server->entryWorld->getVoteController();
    $votes->addVoteOption('DM map', 'change_map dm1');

    $server->send($client, new ClCallVoteChunk('option', $value, ''));

    expect($votes->isVoteRunning())->toBeFalse();
    expect($server->entryWorld->getMap()->getName())->toBe('dm1');
})->with(['change_map', 'Change map', 'change_map ctf1', 'change_gamemode', 'Change gamemode', 'change_gamemode ctf ctf1']);

test('RCON dispatches map and gamemode commands with runtime arguments and leaves other worlds untouched', function () {
    $server = new RconTestServer;
    $other  = $server->addWorld('other');
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('change_map ctf1; change_gamemode CTF'));
    $server->tick();

    expect($server->entryWorld->getMap()->getName())->toBe('ctf1');
    expect($server->entryWorld->getGameController()->getGameType())->toBe('CTF');
    expect($other->getMap()->getName())->toBe('dm1');
    expect($other->getGameController()->getGameType())->toBe('DM');
    expect($client->rcon()->authenticated)->toBeTrue();
});

test('registered reload commands preserve RCON authentication but disconnection clears the session', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $map     = $server->entryWorld->getMap();
    $session = $client->rcon();

    $server->send($client, new RconCmdChunk('reload'));
    $server->tick();

    expect($server->entryWorld->getMap())->not->toBe($map);
    expect($client->state)->toBe(ConnectionSlot::STATE_LOADING);
    expect($client->rcon())->toBe($session);
    expect($client->rcon()->authenticated)->toBeTrue();
    $client->closeConnection('test disconnect');
    expect($client->rcon())->not->toBe($session);
    expect($client->rcon()->authenticated)->toBeFalse();
    expect($client->rcon()->advertisedCommands)->toBeEmpty();
});

test('RCON custom commands receive the same parsed arguments as chat commands', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand(type: AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER, params: 's[value]');
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('RECORD "value; # with spaces"'));
    $server->send($client, new ClSayChunk(false, '/RECORD "value; # with spaces"'));

    expect($command->executions)->toHaveCount(2);
    expect($command->executions[0]['arguments'])->toBe(['value; # with spaces']);
    expect($command->executions[0]['arguments'])->toBe($command->executions[1]['arguments']);
});

test('typed parameter declarations supply the same arguments to chat RCON and passed votes', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand(type: AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER, params: 'i[count] f[scale] s[name] ?r[reason]');
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));
    $line = 'record 3 1.5 "a name" because text';

    $server->send($client, new RconCmdChunk($line));
    $server->send($client, new ClSayChunk(false, '/'.$line));
    $server->entryWorld->getVoteController()->addVoteOption('Typed action', $line);
    $server->send($client, new ClCallVoteChunk('option', 'Typed action', ''));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);

    expect($command->executions)->toHaveCount(3);
    foreach ($command->executions as $execution) {
        expect($execution['arguments'])->toBe(['3', '1.5', 'a name', 'because text']);
    }
});

test('parameter validation prevents malformed input from executing or becoming a vote option', function (string $line) {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand(type: AbstractCommand::TYPE_CHAT | AbstractCommand::TYPE_SERVER, params: 'i[count] f[scale]');
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk($line));
    $server->send($client, new ClSayChunk(false, '/'.$line));
    $server->send($client, new RconCmdChunk('add_vote "Invalid typed action" "'.$line.'"'));

    expect($command->executions)->toBeEmpty();
    expect($server->entryWorld->getVoteController()->getVoteOption('Invalid typed action'))->toBeNull();
})->with(['record invalid 1.5', 'record 1 NaN', 'record 1', 'record 1 1.5 extra']);

test('RCON shutdown through a registered command stops subsequent execution', function () {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->entryWorld->registerCommand(new RconTestCommand('shutdown', handler: function (AbstractWorld $world): void {
        $world->getServer()->shutdown();
    }));
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk('shutdown; record'));
    $server->send($client, new RconCmdChunk('record'));

    expect($server->shutdownRequested)->toBeTrue();
    expect($command->executions)->toBeEmpty();
});

test('command lines split separators and comments without changing quoted arguments or escapes', function (string $line, array $expected) {
    expect(CommandLine::split($line))->toBe($expected);
})->with([
    [' record ; other # ignored; shutdown', ['record', 'other']],
    ['record "hello; # world"', ['record "hello; # world"']],
    ['record "" "quoted \\"value\\"" "a\\\\b"', ['record "" "quoted \\"value\\"" "a\\\\b"']],
    [" \t ; # nothing", []],
]);

test('invalid RCON command lines cannot execute a valid prefix', function (string $line) {
    $server  = new RconTestServer;
    $client  = $server->addClient();
    $command = new RconTestCommand;
    $server->entryWorld->registerCommand($command);
    $server->send($client, new RconAuthChunk('', 'test-secret'));

    $server->send($client, new RconCmdChunk($line));

    expect($command->executions)->toBeEmpty();
    expect($client->state)->toBe(ConnectionSlot::STATE_INGAME);
})->with(['record; "unclosed', "record\n", 'record '.str_repeat('x', 512), "record \xff"]);

test('the 0.6 login-name field does not affect password authentication', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->send($client, new RconAuthChunk('unused-name', 'test-secret'));

    expect($client->rcon()->authenticated)->toBeTrue();
});

test('replayed RCON login attempts are not counted again', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $auth   = (new RconAuthChunk('', 'wrong'))->setSequence(1);

    $server->send($client, $auth);
    $server->send($client, $auth);

    expect($client->rcon()->authTries)->toBe(1);
    expect(rconLines($server->socket($client)))->toBe(['Wrong password 1/3.']);
});

test('RCON reports command errors to the issuer, not other administrators', function () {
    $server = new RconTestServer;
    $admin  = $server->addClient();
    $other  = $server->addClient(port: 8301);
    $server->entryWorld->registerCommand(new RconTestCommand('fail', handler: function (): void {
        throw new RuntimeException('Command failed privately');
    }));
    $server->send($admin, new RconAuthChunk('', 'test-secret'));
    $server->send($other, new RconAuthChunk('', 'test-secret'));
    $server->socket($admin)->packets = [];
    $server->socket($other)->packets = [];

    $server->send($admin, new RconCmdChunk('fail'));
    $server->getRcon()->update();

    expect(rconLines($server->socket($admin)))->toContain('Command failed privately');
    expect(implode("\n", rconLines($server->socket($other))))->not->toContain('Command failed privately');
});

test('RCON configuration and registered handlers are not kept alive by console listeners', function () {
    $server    = new RconTestServer;
    $reference = WeakReference::create($server->getRcon());
    unset($server);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

test('RCON validates configuration', function () {
    expect(fn () => new RconTestServer(maxTries: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new RconTestServer(banDuration: -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => new RconTestServer(password: "a\0b"))->toThrow(InvalidArgumentException::class);
});
