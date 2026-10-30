<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Commands\WhisperCommand;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\RawPayload;
use Tests\Support\RecordingGameServer;

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

    expect(recordingServer($world)->sentTeeIndexes)->toBe([1, 0]);
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
    $command->execute($world, $from, ['', '', 'targetplayer', 'secret']);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([1, 0]);
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
    $command->execute($world, $from, ['', '', 'NonExistent', 'secret']);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0]);
});

test('whisper regex parses quoted name with spaces', function () {
    $message = '/w "[D] abidi" hello';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);
    assert(isset($matches[1], $matches[2], $matches[3]));

    $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
    $whisperMsg = $matches[3];

    expect($targetName)->toBe('[D] abidi');
    expect($whisperMsg)->toBe('hello');
});

test('whisper regex parses unquoted single-word name', function () {
    $message = '/w PlayerName hello world';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);
    assert(isset($matches[1], $matches[2], $matches[3]));

    $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
    $whisperMsg = $matches[3];

    expect($targetName)->toBe('PlayerName');
    expect($whisperMsg)->toBe('hello world');
});

test('/whisper command works with quoted name', function () {
    $message = '/whisper "Someone" secret message here';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);
    assert(isset($matches[1], $matches[2], $matches[3]));

    $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
    $whisperMsg = $matches[3];

    expect($targetName)->toBe('Someone');
    expect($whisperMsg)->toBe('secret message here');
});

test('regular chat without slash is not matched as whisper', function () {
    $message = 'hello everyone';

    $isWhisper = preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);

    expect($isWhisper)->toBe(0);
});
