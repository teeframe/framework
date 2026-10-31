<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\Connection\ConnectionSnap;
use TeeFrame\Network\Connection\SnapHandler;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\RawPayload;
use TeeFrame\Network\SnapItems\ObjProjectileItem;
use Tests\Support\RecordingConnection;

function lastRecoverySnapshot(RecordingConnection $connection): SnapEmptyChunk|SnapSingleChunk|SnapSliceChunk
{
    $packet = end($connection->sentPackets);
    if (! $packet instanceof DefaultPacket) {
        throw new LogicException('No snapshot packet was sent');
    }
    $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
    assert($decoded instanceof DefaultPacket);
    $chunk = $decoded->getChunks()[0];
    assert($chunk instanceof SnapEmptyChunk || $chunk instanceof SnapSingleChunk || $chunk instanceof SnapSliceChunk);

    return $chunk;
}

/** @return array<int, ConnectionSnap> */
function recoverySnapshotHistory(SnapHandler $snaps): array
{
    return (new ReflectionProperty(SnapHandler::class, 'sentList'))->getValue($snaps);
}

test('missing snapshot ACKs clear the old base and recover from a full snapshot', function (int $missingTick) {
    $connection = new RecordingConnection(new TickHandler(10));
    $snaps      = $connection->snaps();
    $old        = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(0);
    $current    = (new ObjProjectileItem(101, 200, 1, 2, 1, 10))->setId(0);
    $snaps->sendItems(10, [$old]);
    $snaps->setLastAckedTick(10);
    $snaps->setLastAckedTick($missingTick);

    $snaps->sendItems(11, [$current]);
    $full = lastRecoverySnapshot($connection);
    expect($full)->toBeInstanceOf(SnapSingleChunk::class);
    assert($full instanceof SnapSingleChunk);
    expect($full->currentTick - $full->deltaTick)->toBe(-1);
    expect($full->snapPayload)->toBe([0, 1, 0, ...$current->encode()]);

    $snaps->setLastAckedTick(11);
    $snaps->sendItems(12, [$current]);
    $unchanged = lastRecoverySnapshot($connection);
    expect($unchanged)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($unchanged->currentTick - $unchanged->deltaTick)->toBe(11);
})->with(['client reset' => -1, 'older missing tick' => 9, 'unsent tick' => 11, 'future tick' => 999]);

test('empty snapshots without an available ACK also advertise the empty base', function (int $missingTick) {
    $connection = new RecordingConnection(new TickHandler(10));
    $connection->snaps()->setLastAckedTick($missingTick);
    $connection->snaps()->sendItems(10, []);

    $chunk = lastRecoverySnapshot($connection);
    expect($chunk)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($chunk->currentTick - $chunk->deltaTick)->toBe(-1);
})->with([-1, 5, 999]);

test('fragmented recovery snapshots consistently advertise and encode the empty base', function () {
    $connection = new RecordingConnection(new TickHandler(10));
    $items      = [];
    $expected   = (new RawPayload)->addInt(0)->addInt(128)->addInt(0);
    for ($i = 0; $i < 128; $i++) {
        $item    = (new ObjProjectileItem(1600 + $i, 1000, 43, 4, 1, 10))->setId($i);
        $items[] = $item;
        $expected->addBytes($item->encode());
    }
    $snaps = $connection->snaps();
    $snaps->sendItems(10, $items);
    $snaps->setLastAckedTick(10);
    $snaps->setLastAckedTick(-1);
    $previousPackets = count($connection->sentPackets);
    $snaps->sendItems(11, $items);

    $packets = array_slice($connection->sentPackets, $previousPackets);
    expect(count($packets))->toBeGreaterThan(1);
    $payload = [];
    foreach ($packets as $packet) {
        $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
        assert($decoded instanceof DefaultPacket);
        $chunk = $decoded->getChunks()[0];
        expect($chunk)->toBeInstanceOf(SnapSliceChunk::class);
        assert($chunk instanceof SnapSliceChunk);
        expect($chunk->currentTick - $chunk->deltaTick)->toBe(-1);
        $payload = [...$payload, ...$chunk->snapPayload];
    }
    expect($payload)->toBe($expected->encode());
});

test('tick zero is a valid acknowledged snapshot base', function () {
    $connection = new RecordingConnection(new TickHandler);
    $item       = (new ObjProjectileItem(100, 200, 1, 2, 1, 0))->setId(0);
    $snaps      = $connection->snaps();
    $snaps->sendItems(0, [$item]);
    $snaps->setLastAckedTick(0);
    $snaps->sendItems(1, [$item]);

    $chunk = lastRecoverySnapshot($connection);
    expect($chunk)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($chunk->currentTick - $chunk->deltaTick)->toBe(0);
});

test('a missing or out of order ACK does not destroy other retained snapshot bases', function () {
    $connection = new RecordingConnection(new TickHandler(10));
    $item       = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(0);
    $snaps      = $connection->snaps();
    $snaps->sendItems(10, [$item]);
    $snaps->sendItems(11, [$item]);
    $snaps->setLastAckedTick(11);
    $snaps->setLastAckedTick(999);
    $snaps->setLastAckedTick(10);
    $snaps->sendItems(12, [$item]);

    $chunk = lastRecoverySnapshot($connection);
    expect($chunk)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($chunk->currentTick - $chunk->deltaTick)->toBe(10);
});

test('snapshot bases expire after the three second retention boundary even without a new ACK', function () {
    $connection = new RecordingConnection(new TickHandler(10));
    $item       = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(0);
    $snaps      = $connection->snaps();
    $snaps->sendItems(10, [$item]);
    $snaps->setLastAckedTick(10);
    $reference = WeakReference::create(array_values(recoverySnapshotHistory($snaps))[0]);
    $snaps->sendItems(160, [$item]);
    $boundary = lastRecoverySnapshot($connection);
    expect($boundary)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($boundary->currentTick - $boundary->deltaTick)->toBe(10);

    $snaps->sendItems(161, [$item]);
    $expired = lastRecoverySnapshot($connection);
    expect($expired)->toBeInstanceOf(SnapSingleChunk::class);
    assert($expired instanceof SnapSingleChunk);
    expect($expired->currentTick - $expired->deltaTick)->toBe(-1);
    expect($expired->snapPayload)->toBe([0, 1, 0, ...$item->encode()]);
    expect($reference->get())->toBeNull();

    $snaps->setLastAckedTick(160);
    $snaps->sendItems(162, [$item]);
    $recovered = lastRecoverySnapshot($connection);
    expect($recovered)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($recovered->currentTick - $recovered->deltaTick)->toBe(160);
});

test('snapshot history stays bounded when ACKs are absent stalled or invalid', function (string $ackMode) {
    $connection = new RecordingConnection(new TickHandler);
    $snaps      = $connection->snaps();
    for ($tick = 1; $tick <= 1000; $tick++) {
        $snaps->sendItems($tick, []);
        if ($ackMode !== 'absent') {
            $snaps->setLastAckedTick(match ($ackMode) {
                'stalled' => 1,
                'reset'   => -1,
                default   => 1_000_000,
            });
        }
        $history = recoverySnapshotHistory($snaps);
        expect(count($history))->toBeLessThanOrEqual(151);
        $connection->sentPackets = [];
    }
    expect($history)->not->toBeEmpty();
    foreach ($history as $snap) {
        expect($snap->getTick())->toBeGreaterThanOrEqual(850);
    }
})->with(['absent', 'stalled', 'reset', 'future']);

test('repeated sends for one tick cannot grow snapshot history indefinitely', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 300; $i++) {
        $connection->snaps()->sendItems(10, []);
    }

    expect(recoverySnapshotHistory($connection->snaps()))->toHaveCount(1);
});

test('flushing snapshot history also releases the delta base', function () {
    $connection = new RecordingConnection(new TickHandler(10));
    $item       = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(0);
    $snaps      = $connection->snaps();
    $snaps->sendItems(10, [$item]);
    $snaps->setLastAckedTick(10);
    $snaps->flushSentList();
    $snaps->sendItems(11, [$item]);

    $chunk = lastRecoverySnapshot($connection);
    expect($chunk)->toBeInstanceOf(SnapSingleChunk::class);
    assert($chunk instanceof SnapSingleChunk);
    expect($chunk->currentTick - $chunk->deltaTick)->toBe(-1);
    expect($chunk->snapPayload)->toBe([0, 1, 0, ...$item->encode()]);
});
