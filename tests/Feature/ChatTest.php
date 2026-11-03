<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Commands\Chat\WhisperCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\ClientMessageHandler;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\RawPayload;
use Tests\Support\RconTestServer;
use Tests\Support\RecordingGameServer;

require_once __DIR__.'/../Support/RconTestServer.php';

class CustomTeamChatTestHandler extends ClientMessageHandler
{
    public int $routingChecks = 0;

    protected function canReceiveTeamChat(AbstractTee $sender, AbstractTee $recipient): bool
    {
        $this->routingChecks++;

        return ($sender->teeIndex & 1) === ($recipient->teeIndex & 1);
    }
}

/** @return array{TestWorld, TickHandler, list<PlayerTee>, CustomTeamChatTestHandler} */
function createCustomTeamChatWorld(): array
{
    $clock   = new TickHandler(100);
    $world   = new TestWorld($clock, new Map);
    $players = [];
    foreach (['Sender', 'Peer', 'Other'] as $name) {
        $player       = new PlayerTee;
        $player->name = $name;
        $world->addTee($player);
        $players[] = $player;
    }
    $players[0]->team = $players[1]->team = GameConstants::TEAM_SPECTATORS;
    $players[2]->team = GameConstants::TEAM_RED;
    recordingServer($world)->clear();

    return [$world, $clock, $players, new CustomTeamChatTestHandler($world)];
}

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

test('ClSayChunk encodes and decodes correctly', function () {
    $chunk = new ClSayChunk(team: false, text: 'hello world');

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClSayChunk::make(new RawPayload($payload));

    expect($decoded->team)->toBeFalse();
    expect($decoded->text)->toBe('hello world');
});

test('ClSayChunk team chat encodes and decodes correctly', function () {
    $chunk = new ClSayChunk(team: true, text: 'team only');

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClSayChunk::make(new RawPayload($payload));

    expect($decoded->team)->toBeTrue();
    expect($decoded->text)->toBe('team only');
});

test('SvChatChunk encodes and decodes correctly', function () {
    $chunk = new SvChatChunk(team: 0, clientId: 3, text: 'hello everyone');

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = SvChatChunk::make(new RawPayload($payload));

    expect($decoded->team)->toBe(0);
    expect($decoded->clientId)->toBe(3);
    expect($decoded->text)->toBe('hello everyone');
});

test('SvChatChunk server message encodes and decodes correctly', function () {
    $chunk = new SvChatChunk(team: 0, clientId: -1, text: 'Server message');

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = SvChatChunk::make(new RawPayload($payload));

    expect($decoded->team)->toBe(0);
    expect($decoded->clientId)->toBe(-1);
    expect($decoded->text)->toBe('Server message');
});

test('chat trims whitespace and truncates to 127 characters rather than bytes', function (string $character) {
    $world = new TestWorld(new TickHandler(100), new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    recordingServer($world)->clear();

    $world->onMessage($tee, new ClSayChunk(false, " \t".str_repeat($character, 128)." \n"));

    $chunks = recordingServer($world)->sentChunks;
    expect($chunks)->toHaveCount(1);
    $chat = $chunks[0];
    assert($chat instanceof SvChatChunk);
    expect($chat->text)->toBe(str_repeat($character, 127));
})->with(['a', 'é', '中', '🎮']);

test('chat throttling counts UTF-8 characters instead of their encoded bytes', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $world->onMessage($tee, new ClSayChunk(false, str_repeat('é', 16)));
    recordingServer($world)->clear();
    for ($i = 0; $i < 100; $i++) {
        $clock->advanceTick();
    }

    $world->onMessage($tee, new ClSayChunk(false, str_repeat('é', 16)));

    expect(recordingServer($world)->sentChunks)->toHaveCount(1);
    expect($tee->lastChatTick)->toBe(200);
});

test('chat broadcast calls sendToTee for each tee', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee1           = new PlayerTee;
    $tee1->name     = 'Player1';
    $tee1->teeIndex = 0;

    $tee2           = new PlayerTee;
    $tee2->name     = 'Player2';
    $tee2->teeIndex = 1;

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setValue($world, [0 => $tee1, 1 => $tee2]);

    $world->onMessage($tee1, new ClSayChunk(team: false, text: 'hello'));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('onMessage handles ClSayChunk by broadcasting', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee1           = new PlayerTee;
    $tee1->name     = 'Player1';
    $tee1->teeIndex = 0;

    $tee2           = new PlayerTee;
    $tee2->name     = 'Player2';
    $tee2->teeIndex = 1;

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setValue($world, [0 => $tee1, 1 => $tee2]);

    $world->onMessage($tee1, new ClSayChunk(team: false, text: 'hello everyone'));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('chat recipients respect team privacy and world boundaries', function (string $mode, int $senderTeam, bool $teamChat) {
    $clock      = new TickHandler(100);
    $server     = new RecordingGameServer($clock);
    $controller = new PvpGameController($clock);
    $controller->setGameMode($mode);
    $world = new TestWorldWithController($clock, new Map, $controller, $server);
    $other = new TestWorldWithController($clock, new Map, new PvpGameController($clock), $server);
    $other->addTee(new PlayerTee);

    $sender = new PlayerTee;
    $world->addTee($sender);
    $sender->team = $senderTeam;
    $expected     = [$sender->teeIndex];
    $teams        = $mode === 'DM' ? [0, 0, -1, -1] : [0, 0, 1, 1, -1, -1];
    foreach ($teams as $team) {
        $recipient = new PlayerTee;
        $world->addTee($recipient);
        $recipient->team = $team;
        // Following a player must not grant access to that player's team chat.
        $recipient->spectatorId = $sender->teeIndex;
        if (! $teamChat || $team === $senderTeam) {
            $expected[] = $recipient->teeIndex;
        }
    }
    $server->clear();

    $world->onMessage($sender, new ClSayChunk(team: $teamChat, text: 'hello'));

    expect($server->sentTeeIndexes)->toBe($expected);
    foreach ($server->sentPairs as $pair) {
        expect($pair['world'])->toBe($world);
        expect($pair['chunk'])->toBeInstanceOf(SvChatChunk::class);
        assert($pair['chunk'] instanceof SvChatChunk);
        expect($pair['chunk']->team)->toBe($teamChat ? 1 : 0);
        expect($pair['chunk']->clientId)->toBe($sender->teeIndex);
        expect($pair['chunk']->text)->toBe('hello');
    }
    if (count($server->sentChunks) > 1) {
        expect($server->sentChunks[0])->not->toBe($server->sentChunks[1]);
    }
})->with([
    'TDM red team'          => ['TDM', 0, true],
    'TDM blue team'         => ['TDM', 1, true],
    'TDM spectators'        => ['TDM', -1, true],
    'CTF red team'          => ['CTF', 0, true],
    'CTF blue team'         => ['CTF', 1, true],
    'CTF spectators'        => ['CTF', -1, true],
    'DM players'            => ['DM', 0, true],
    'DM spectators'         => ['DM', -1, true],
    'public player chat'    => ['TDM', 0, false],
    'public spectator chat' => ['CTF', -1, false],
]);

test('team chat recipient hooks override network teams without crossing world boundaries', function () {
    [$world, $clock, $players, $handler] = createCustomTeamChatWorld();
    $server                              = recordingServer($world);
    $otherWorld                          = new TestWorld($clock, new Map, $server);
    $otherWorld->addTee(new PlayerTee);
    $server->clear();

    $handler->handle($players[0], new ClSayChunk(true, 'Custom group'));

    expect($server->sentTeeIndexes)->toBe([0, 2]);
    expect($handler->routingChecks)->toBe(3);
    foreach ($server->sentPairs as $pair) {
        expect($pair['world'])->toBe($world);
        $chat = $pair['chunk'];
        assert($chat instanceof SvChatChunk);
        expect([$chat->team, $chat->clientId, $chat->text])->toBe([1, 0, 'Custom group']);
    }
    expect($server->sentChunks[0])->not->toBe($server->sentChunks[1]);
});

test('custom team routing retains Unicode normalization spam protection and chat timestamps', function () {
    [$world, $clock, $players, $handler] = createCustomTeamChatWorld();
    $server                              = recordingServer($world);
    $message                             = " \t".str_repeat('🎮', 128)." \n";
    $handler->handle($players[0], new ClSayChunk(true, $message));

    expect($server->sentTeeIndexes)->toBe([0, 2]);
    foreach ($server->sentChunks as $chat) {
        assert($chat instanceof SvChatChunk);
        expect($chat->text)->toBe(str_repeat('🎮', 127));
    }
    expect($players[0]->lastChatTick)->toBe(100);
    $server->clear();
    $handler->handle($players[0], new ClSayChunk(true, $message));
    expect($server->sentChunks)->toBeEmpty();
    expect($handler->routingChecks)->toBe(3);

    for ($i = 0; $i < 442; $i++) {
        $clock->advanceTick();
    }
    $handler->handle($players[0], new ClSayChunk(true, $message));
    expect($server->sentChunks)->toBeEmpty();
    expect($players[0]->lastChatTick)->toBe(100);
    $clock->advanceTick();
    $handler->handle($players[0], new ClSayChunk(true, $message));
    expect($server->sentTeeIndexes)->toBe([0, 2]);
    expect($players[0]->lastChatTick)->toBe(543);
    expect($handler->routingChecks)->toBe(6);
});

test('team routing hooks do not intercept public chat whispers or command errors', function (bool $teamChat, string $message, array $recipients) {
    [$world, , $players, $handler] = createCustomTeamChatWorld();
    $handler->handle($players[0], new ClSayChunk($teamChat, $message));

    expect(recordingServer($world)->sentTeeIndexes)->toBe($recipients);
    expect($handler->routingChecks)->toBe(0);
    expect($players[0]->lastChatTick)->toBe(100);
})->with([
    'public chat'         => [false, 'Public message', [0, 1, 2]],
    'cross-group whisper' => [true, '/w Peer secret', [0, 1]],
    'unknown command'     => [true, '/unknown secret', [0]],
    'invalid command'     => [true, '/w Peer', [0]],
]);

test('empty chat never invokes custom team routing', function (bool $teamChat) {
    [$world, , $players, $handler] = createCustomTeamChatWorld();
    $handler->handle($players[0], new ClSayChunk($teamChat, " \t\n"));

    expect(recordingServer($world)->sentChunks)->toBeEmpty();
    expect($handler->routingChecks)->toBe(0);
    expect($players[0]->lastChatTick)->toBe(0);
})->with([false, true]);

test('muting is checked before custom team routing and command dispatch', function (string $message) {
    $server                           = new RconTestServer(clock: new TickHandler(100));
    $sender                           = $server->addClient();
    $peer                             = $server->addClient(port: 8301);
    $peer->playerTee()->name          = 'Peer';
    $handler                          = new CustomTeamChatTestHandler($server->entryWorld);
    $server->socket($sender)->packets = [];
    $server->socket($peer)->packets   = [];
    $server->getChatMutes()->mute($sender->destinationAddress, 60, 'Test mute');

    $handler->handle($sender->playerTee(), new ClSayChunk(true, $message));

    expect($handler->routingChecks)->toBe(0);
    expect($sender->playerTee()->lastChatTick)->toBe(0);
    expect($server->socket($peer)->chunks(SvChatChunk::class))->toBeEmpty();
    $replies = $server->socket($sender)->chunks(SvChatChunk::class);
    expect($replies)->toHaveCount(1);
    expect($replies[0]->text)->toStartWith('You are muted for ');
    expect($replies[0]->text)->toEndWith('Test mute');
})->with(['Team message', '/w Peer secret', '/unknown']);

test('onMessage handles whisper command via ClSayChunk', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $from           = new PlayerTee;
    $from->name     = 'Sender';
    $from->teeIndex = 0;

    $target           = new PlayerTee;
    $target->name     = 'TargetPlayer';
    $target->teeIndex = 1;

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setValue($world, [$from, $target]);

    $world->onMessage($from, new ClSayChunk(team: false, text: '/w targetplayer secret'));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('WhisperCommand finds target by name case-insensitively', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $from           = new PlayerTee;
    $from->name     = 'Sender';
    $from->teeIndex = 0;

    $target           = new PlayerTee;
    $target->name     = 'TargetPlayer';
    $target->teeIndex = 1;

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setValue($world, [$from, $target]);

    $command = new WhisperCommand;
    $command->execute($world, $from, ['targetplayer', 'secret']);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('whispers prefer an exact name even after multiple case-insensitive matches', function (string $targetName, bool $reverseOrder) {
    $world        = new TestWorld(new TickHandler(100), new Map);
    $sender       = new PlayerTee;
    $sender->name = 'Sender';
    $world->addTee($sender);
    $names  = $reverseOrder ? ['alice', 'aLiCe', 'Alice'] : ['Alice', 'aLiCe', 'alice'];
    $target = null;
    foreach ($names as $name) {
        $player       = new PlayerTee;
        $player->name = $name;
        $world->addTee($player);
        if ($name === $targetName) {
            $target = $player;
        }
    }
    assert($target instanceof PlayerTee);
    recordingServer($world)->clear();

    $world->onMessage($sender, new ClSayChunk(false, '/w '.$targetName.' secret'));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([$sender->teeIndex, $target->teeIndex]);
    foreach (recordingServer($world)->sentChunks as $chunk) {
        assert($chunk instanceof SvChatChunk);
        expect($chunk->text)->toBe('secret');
    }
})->with(['Alice', 'alice'])->with([false, true]);

test('ambiguous whisper names produce only a private error without sending the message', function (bool $reverseOrder, bool $quoted) {
    $world        = new TestWorld(new TickHandler(100), new Map);
    $sender       = new PlayerTee;
    $sender->name = 'Sender';
    $world->addTee($sender);
    foreach ($reverseOrder ? ['alice', 'Alice'] : ['Alice', 'alice'] as $name) {
        $player       = new PlayerTee;
        $player->name = $name;
        $world->addTee($player);
    }
    recordingServer($world)->clear();

    $world->onMessage($sender, new ClSayChunk(false, $quoted ? '/w "ALICE" secret' : '/w ALICE secret'));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([$sender->teeIndex]);
    $chunk = recordingServer($world)->sentChunks[0];
    assert($chunk instanceof SvChatChunk);
    expect($chunk->team)->toBe(0);
    expect($chunk->clientId)->toBe(-1);
    expect($chunk->text)->toBe("Multiple players match 'ALICE'. Use the exact name.");
})->with([false, true])->with([false, true]);

test('case-insensitive whisper fallback considers only players in the same world', function () {
    $clock        = new TickHandler(100);
    $server       = new RecordingGameServer($clock);
    $world        = new TestWorld($clock, new Map, $server);
    $other        = new TestWorld($clock, new Map, $server);
    $sender       = new PlayerTee;
    $sender->name = 'Sender';
    $world->addTee($sender);
    $target       = new PlayerTee;
    $target->name = 'Alice';
    $world->addTee($target);
    $outsider       = new PlayerTee;
    $outsider->name = 'alice';
    $other->addTee($outsider);
    $server->clear();

    $world->onMessage($sender, new ClSayChunk(false, '/w ALICE secret'));

    expect($server->sentTeeIndexes)->toBe([$sender->teeIndex, $target->teeIndex]);
    foreach ($server->sentPairs as $pair) {
        expect($pair['world'])->toBe($world);
    }
});

test('WhisperCommand notifies sender when target not found', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $from           = new PlayerTee;
    $from->name     = 'Sender';
    $from->teeIndex = 0;

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setValue($world, [$from]);

    $command = new WhisperCommand;
    $command->execute($world, $from, ['NonExistent', 'secret']);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0]);
});

test('whisper parameters parse names and messages through both command names', function (string $line, array $arguments) {
    expect(CommandLine::match(new WhisperCommand, $line))->toBe($arguments);
})->with([
    ['w "[D] abidi" hello', ['[D] abidi', 'hello']],
    ['w PlayerName hello world', ['PlayerName', 'hello world']],
    ['WHISPER "Someone" secret message here', ['Someone', 'secret message here']],
]);

test('regular chat is not matched as a whisper command', function () {
    expect(CommandLine::match(new WhisperCommand, 'hello everyone'))->toBeNull();
});

test('unknown chat commands receive a private error instead of being broadcast', function (string $message, bool $teamChat) {
    $world  = createWorld(new Map);
    $sender = new PlayerTee;
    $other  = new PlayerTee;
    $world->addTee($other);
    $world->addTee($sender);
    recordingServer($world)->clear();

    $world->onMessage($sender, new ClSayChunk($teamChat, $message));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([$sender->teeIndex]);
    $chunk = recordingServer($world)->sentChunks[0];
    assert($chunk instanceof SvChatChunk);
    expect($chunk->team)->toBe(0);
    expect($chunk->clientId)->toBe(-1);
    expect($chunk->text)->toBe('Command not found');
})->with(['/unknown private text', '/', '/sv_world_map dm1', '/reload'])->with([false, true]);

test('invalid chat commands receive private usage errors instead of broadcasting their arguments', function (string $message) {
    $world  = createWorld(new Map);
    $sender = new PlayerTee;
    $other  = new PlayerTee;
    $world->addTee($sender);
    $world->addTee($other);
    recordingServer($world)->clear();

    $world->onMessage($sender, new ClSayChunk(false, $message));

    expect(recordingServer($world)->sentTeeIndexes)->toBe([$sender->teeIndex]);
    $chunk = recordingServer($world)->sentChunks[0];
    assert($chunk instanceof SvChatChunk);
    expect($chunk->text)->toStartWith('Usage: /');
    expect($chunk->text)->not->toContain('private text');
})->with(['/w Receiver', '/w "Receiver', '/whisper Receiver "private text" extra', '/ping extra']);
