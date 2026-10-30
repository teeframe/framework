<?php

use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

test('addTee marks PlayerTee as spawning', function () {
    $world     = createWorld(new Map(__DIR__.'/../dm1.map'));
    $tee       = new PlayerTee;
    $tee->name = 'NewPlayer';

    $world->addTee($tee);

    expect($tee->spawning)->toBeTrue();
    expect($tee->teeIndex)->toBe(0);
});

test('addTee broadcasts enter chat message to all tees', function () {
    $world          = createWorld(new Map(__DIR__.'/../dm1.map'));
    $existing       = new PlayerTee;
    $existing->name = 'Existing';
    $world->addTee($existing);
    recordingServer($world)->clear();
    $newTee       = new PlayerTee;
    $newTee->name = 'NewPlayer';

    $world->addTee($newTee);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('addTee enter message uses server clientId -1', function () {
    $world     = createWorld(new Map(__DIR__.'/../dm1.map'));
    $tee       = new PlayerTee;
    $tee->name = 'Joiner';

    $world->addTee($tee);

    $server = recordingServer($world);
    expect($server->sentTeeIndexes)->toBe([0]);
    expect($server->sentChunks)->toHaveCount(1);
    $chat = $server->sentChunks[0];
    assert($chat instanceof SvChatChunk);
    expect($chat->clientId)->toBe(-1);
});

test('addTee enter message contains tee name', function () {
    $world     = createWorld(new Map(__DIR__.'/../dm1.map'));
    $tee       = new PlayerTee;
    $tee->name = 'FancyName';

    $world->addTee($tee);

    $server = recordingServer($world);
    expect($server->sentTeeIndexes)->toBe([0]);
    expect($server->sentChunks)->toHaveCount(1);
    $chat = $server->sentChunks[0];
    expect($chat)->toBeInstanceOf(SvChatChunk::class);
    assert($chat instanceof SvChatChunk);
    expect($chat->clientId)->toBe(-1);
    expect($chat->text)->toBe("'FancyName' entered and joined the game");
});
