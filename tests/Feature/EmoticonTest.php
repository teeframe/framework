<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
use TeeFrame\Network\RawPayload;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('ClEmoticonChunk encodes and decodes correctly', function () {
    $chunk = new ClEmoticonChunk(emoticon: 5);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClEmoticonChunk::make(new RawPayload($payload));

    expect($decoded->emoticon)->toBe(5);
});

test('SvEmoticonChunk encodes and decodes correctly', function () {
    $chunk = new SvEmoticonChunk(clientId: 3, emoticon: 11);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = SvEmoticonChunk::make(new RawPayload($payload));

    expect($decoded->clientId)->toBe(3);
    expect($decoded->emoticon)->toBe(11);
});

test('sendEmoticon broadcasts to all tees', function () use ($mapPath, $mapExists) {
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

    $world->sendEmoticon(0, 7);

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});

test('onMessage handles ClEmoticonChunk by broadcasting', function () use ($mapPath, $mapExists) {
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

    $world->onMessage($tee1, new ClEmoticonChunk(emoticon: 2));

    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});
