<?php

use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

test('removeTee broadcasts leave message without reason', function () {
    $world         = createWorld(new Map(__DIR__.'/../dm1.map'));
    $leaving       = new PlayerTee;
    $leaving->name = 'Leaver';
    $staying       = new PlayerTee;
    $staying->name = 'Stayer';
    $world->addTee($leaving);
    $world->addTee($staying);
    recordingServer($world)->clear();

    $world->removeTee($leaving);

    expect(recordingServer($world)->sentTeeIndexes)->toBe([0, 1]);
});

test('removeTee broadcasts leave message with reason', function () {
    $world         = createWorld(new Map(__DIR__.'/../dm1.map'));
    $leaving       = new PlayerTee;
    $leaving->name = 'Leaver';
    $world->addTee($leaving);
    $server = recordingServer($world);
    $server->clear();

    $world->removeTee($leaving, 'timeout');

    expect($server->sentTeeIndexes)->toBe([0]);
    expect($server->sentChunks)->toHaveCount(1);
    $chat = $server->sentChunks[0];
    expect($chat)->toBeInstanceOf(SvChatChunk::class);
    assert($chat instanceof SvChatChunk);
    expect($chat->clientId)->toBe(-1);
    expect($chat->text)->toBe("'Leaver' has left the game (timeout)");
});

test('removeTee leave message without reason has plain format', function () {
    $world         = createWorld(new Map(__DIR__.'/../dm1.map'));
    $leaving       = new PlayerTee;
    $leaving->name = 'QuietLeaver';
    $world->addTee($leaving);
    $server = recordingServer($world);
    $server->clear();

    $world->removeTee($leaving);

    expect($server->sentChunks)->toHaveCount(1);
    $chat = $server->sentChunks[0];
    expect($chat)->toBeInstanceOf(SvChatChunk::class);
    assert($chat instanceof SvChatChunk);
    expect($chat->clientId)->toBe(-1);
    expect($chat->text)->toBe("'QuietLeaver' has left the game");
});

test('removeTee frees the tee index', function () {
    $world        = createWorld(new Map(__DIR__.'/../dm1.map'));
    $first        = new PlayerTee;
    $first->name  = 'First';
    $second       = new PlayerTee;
    $second->name = 'Second';
    $world->addTee($first);
    $world->addTee($second);
    expect($first->teeIndex)->toBe(0);
    expect($second->teeIndex)->toBe(1);

    $world->removeTee($first);
    $third       = new PlayerTee;
    $third->name = 'Third';
    $world->addTee($third);

    expect($third->teeIndex)->toBe(0);
});
