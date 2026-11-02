<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Server\ConnectionSlot;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

test('vote starts ends and replacements stay synchronized throughout READY', function (bool $initialized) {
    $server = new RconTestServer(clock: new TickHandler(100));
    $world  = $server->entryWorld;
    $server->addClient(port: 8301);
    $joining = $server->addClient(state: ConnectionSlot::STATE_LOADING);
    $loading = $server->addClient(port: 8302, state: ConnectionSlot::STATE_LOADING);
    $other   = $server->addClient($server->addWorld('other'), port: 8303);
    $server->send($joining, new ReadyChunk);
    if ($initialized) {
        $server->send($joining, new ClStartInfoChunk('Joiner', '', 0, 'default', false, 0, 0));
    }
    $server->socket($joining)->packets = [];
    $server->socket($loading)->packets = [];
    $server->socket($other)->packets   = [];
    $votes                             = $world->getVoteController();
    $votes->startVote($world, 'First', 'echo first', 'reason');
    $votes->tick($world);
    $votes->endVote($world);
    $votes->startVote($world, 'Second', 'echo second', 'reason');
    $sets = $server->socket($joining)->chunks(SvVoteSetChunk::class);
    expect(array_map(fn (SvVoteSetChunk $chunk) => [$chunk->timeout, $chunk->description], $sets))
        ->toBe([[25, 'First'], [0, ''], [25, 'Second']]);
    $statuses = $server->socket($joining)->chunks(SvVoteStatusChunk::class);
    expect($statuses)->toHaveCount(1);
    expect($statuses[0]->total)->toBe(1);
    expect($server->socket($loading)->chunks(SvVoteSetChunk::class))->toBeEmpty();
    expect($server->socket($other)->chunks(SvVoteSetChunk::class))->toBeEmpty();

    if (! $initialized) {
        $server->send($joining, new ClStartInfoChunk('Joiner', '', 0, 'default', false, 0, 0));
    }
    $server->send($joining, new EnterGameChunk);
    $votes->tick($world);
    expect($joining->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($server->socket($joining)->chunks(SvVoteSetChunk::class))->toHaveCount(3);
    expect($server->socket($joining)->chunks(SvVoteStatusChunk::class)[1]->total)->toBe(2);
})->with([false, true]);

test('a vote received at READY is cleared even when it ends before start info', function () {
    $server = new RconTestServer(clock: new TickHandler(100));
    $world  = $server->entryWorld;
    $votes  = $world->getVoteController();
    $votes->startVote($world, 'Existing', 'echo existing', 'reason');
    $joining = $server->addClient(state: ConnectionSlot::STATE_LOADING);
    $server->send($joining, new ReadyChunk);
    expect($server->socket($joining)->chunks(SvVoteStatusChunk::class))->toHaveCount(1);
    $votes->endVote($world);
    $server->send($joining, new ClStartInfoChunk('Joiner', '', 0, 'default', false, 0, 0), new EnterGameChunk);
    $sets = $server->socket($joining)->chunks(SvVoteSetChunk::class);
    expect(array_map(fn (SvVoteSetChunk $chunk) => $chunk->timeout, $sets))->toBe([25, 0]);
    expect($votes->isVoteRunning())->toBeFalse();
});
