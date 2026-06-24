<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\RawPayload;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
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

test('sendChat calls sendToTee for each tee', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee1 = new PlayerTee;
    $tee1->name = 'Player1';
    $tee1->teeIndex = 0;

    $tee2 = new PlayerTee;
    $tee2->name = 'Player2';
    $tee2->teeIndex = 1;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $tee1, 1 => $tee2]);

    $world->sendChat($tee1, false, 'hello');

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});

test('onMessage handles ClSayChunk by broadcasting', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee1 = new PlayerTee;
    $tee1->name = 'Player1';
    $tee1->teeIndex = 0;

    $tee2 = new PlayerTee;
    $tee2->name = 'Player2';
    $tee2->teeIndex = 1;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $tee1, 1 => $tee2]);

    $world->onMessage($tee1, new ClSayChunk(team: false, text: 'hello everyone'));

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});

test('onMessage handles whisper command via ClSayChunk', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $from = new PlayerTee;
    $from->name = 'Sender';
    $from->teeIndex = 0;

    $target = new PlayerTee;
    $target->name = 'TargetPlayer';
    $target->teeIndex = 1;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [$from, $target]);

    $world->onMessage($from, new ClSayChunk(team: false, text: '/w targetplayer secret'));

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([1, 0]);
});

test('sendWhisper finds target by name case-insensitively', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $from = new PlayerTee;
    $from->name = 'Sender';
    $from->teeIndex = 0;

    $target = new PlayerTee;
    $target->name = 'TargetPlayer';
    $target->teeIndex = 1;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [$from, $target]);

    $world->sendWhisper($from, 'targetplayer', 'secret');

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([1, 0]);
});

test('sendWhisper notifies sender when target not found', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $from = new PlayerTee;
    $from->name = 'Sender';
    $from->teeIndex = 0;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [$from]);

    $world->sendWhisper($from, 'NonExistent', 'secret');

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0]);
});

test('whisper regex parses quoted name with spaces', function () {
    $message = '/w "[D] abidi" hello';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);

    $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
    $whisperMsg = $matches[3];

    expect($targetName)->toBe('[D] abidi');
    expect($whisperMsg)->toBe('hello');
});

test('whisper regex parses unquoted single-word name', function () {
    $message = '/w PlayerName hello world';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);

    $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
    $whisperMsg = $matches[3];

    expect($targetName)->toBe('PlayerName');
    expect($whisperMsg)->toBe('hello world');
});

test('/whisper command works with quoted name', function () {
    $message = '/whisper "Someone" secret message here';

    preg_match('/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s', $message, $matches);

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