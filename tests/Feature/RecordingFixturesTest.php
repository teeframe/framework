<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvSoundGlobalChunk;
use Tests\Support\RecordingConnection;
use Tests\Support\RecordingGameServer;

test('test worlds create independent initialized recording servers', function () {
    $clock        = new TickHandler(100);
    $first        = new TestWorld($clock, new Map);
    $second       = new TestWorld($clock, new Map);
    $firstServer  = recordingServer($first);
    $secondServer = recordingServer($second);
    expect($firstServer)->not->toBe($secondServer);

    $firstServer->sendToTee($first, 0, new SvChatChunk(0, -1, 'First world'));
    $firstServer->kick($first, 0, 'First kick');
    expect($secondServer->sentChunks)->toBeEmpty();
    expect($secondServer->kickedTees)->toBeEmpty();

    $secondServer->sendToTee($second, 0, new SvChatChunk(0, -1, 'Second world'));
    $firstServer->clear();
    expect($firstServer->sentChunks)->toBeEmpty();
    expect($firstServer->kickedTees)->toBeEmpty();
    expect($secondServer->sentChunks)->toHaveCount(1);

    $first->reload(gameMode: 'DM');
    $first->doTick();
    expect($first->getGameController()->getGameType())->toBe('DM');
});

test('recorded sound assertions distinguish worlds sharing a recording server and tee index', function () {
    $clock  = new TickHandler;
    $server = new RecordingGameServer($clock);
    $first  = new TestWorld($clock, new Map, $server);
    $second = new TestWorld($clock, new Map, $server);
    $first->addTee(new PlayerTee);
    $second->addTee(new PlayerTee);

    $server->sendToTee($first, 0, new SvSoundGlobalChunk(1));
    $server->sendToTee($second, 0, new SvSoundGlobalChunk(2));
    expect(soundsSentToTee($first, 0))->toBe([1]);
    expect(soundsSentToTee($second, 0))->toBe([2]);
});

test('recording connections keep captured packets local to each fixture', function () {
    $clock  = new TickHandler;
    $first  = new RecordingConnection($clock);
    $second = new RecordingConnection($clock);
    $first->snaps()->sendItems(1, []);

    expect($first->sentPackets)->toHaveCount(1);
    expect($second->sentPackets)->toBeEmpty();
});
