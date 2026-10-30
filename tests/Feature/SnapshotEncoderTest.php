<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\RawPayload;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjProjectileItem;
use TeeFrame\Network\SnapshotEncoder;
use Tests\Support\RecordingConnection;

test('snapshot encoding requires no connection clock or previous call state', function () {
    $encoder   = new SnapshotEncoder;
    $item      = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(2);
    $items     = $encoder->indexItems([$item]);
    $first     = $encoder->encode(10, 11, $items);
    $unchanged = $encoder->encode(11, 1, $items, $items);
    $again     = $encoder->encode(10, 11, $items);

    expect($unchanged)->toHaveCount(1);
    expect($unchanged[0])->toBeInstanceOf(SnapEmptyChunk::class);
    expect($first[0]->encode())->toBe($again[0]->encode());
    expect($item->getInts())->toBe([100, 200, 1, 2, 1, 10]);
    expect($item->getId())->toBe(2);
});

test('full single snapshots preserve their packed payload and CRC', function () {
    $encoder = new SnapshotEncoder;
    $item    = (new ObjProjectileItem(1744, 1072, 43, 4, 0, 100))->setId(2);
    $chunks  = $encoder->encode(100, 101, $encoder->indexItems([$item]));

    expect($chunks)->toHaveCount(1);
    $chunk = $chunks[0];
    expect($chunk)->toBeInstanceOf(SnapSingleChunk::class);
    assert($chunk instanceof SnapSingleChunk);
    expect($chunk->currentTick)->toBe(100);
    expect($chunk->deltaTick)->toBe(101);
    expect($chunk->crc)->toBe(2963);
    expect($chunk->snapPayload)->toBe([0, 1, 0, ...$item->encode()]);
    expect($chunk->size)->toBe(count($chunk->snapPayload));
});

test('empty snapshots retain the supplied tick and delta distance', function () {
    $chunk = (new SnapshotEncoder)->encode(200, 3, [])[0];

    expect($chunk)->toBeInstanceOf(SnapEmptyChunk::class);
    assert($chunk instanceof SnapEmptyChunk);
    expect($chunk->currentTick)->toBe(200);
    expect($chunk->deltaTick)->toBe(3);
});

test('snapshot fragments round trip in zero six field order with zero based part numbers', function () {
    $encoder  = new SnapshotEncoder;
    $rawItems = [];
    for ($i = 0; $i < 128; $i++) {
        $rawItems[] = (new ObjProjectileItem(1600 + $i, 1000, 43, 4, 1, 100))->setId($i);
    }
    $items     = $encoder->indexItems($rawItems);
    [$changes] = $encoder->calculateDelta($items);
    $expected  = (new RawPayload)->addInt(0)->addInt(128)->addInt(0)->addBytes($changes)->encode();
    $chunks    = $encoder->encode(101, 1, $items);
    expect(count($chunks))->toBeGreaterThan(1);
    $reassembled = [];

    foreach ($chunks as $index => $chunk) {
        $packet = PacketDecoder::decodeFromRaw((new DefaultPacket([$chunk], ack: 0))->encodeToSend());
        assert($packet instanceof DefaultPacket);
        $decoded = $packet->getChunks()[0];
        expect($decoded)->toBeInstanceOf(SnapSliceChunk::class);
        assert($decoded instanceof SnapSliceChunk);
        expect($decoded->currentTick)->toBe(101);
        expect($decoded->deltaTick)->toBe(1);
        expect($decoded->totalNumber)->toBe(count($chunks));
        expect($decoded->currentNumber)->toBe($index);
        expect($decoded->crc)->toBe($encoder->calculateCrc($items));
        expect($decoded->size)->toBe(count($decoded->snapPayload));
        expect($decoded->size)->toBeLessThanOrEqual(NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);
        $reassembled = [...$reassembled, ...$decoded->snapPayload];
    }

    expect($reassembled)->toBe($expected);
});

test('oversized snapshots fail before the connection sends any fragments', function () {
    $item = new class extends AbstractSnapItem
    {
        public function __construct()
        {
            parent::__construct(2);
            $this->setId(0);
        }

        public function getInts(): array
        {
            return array_fill(0, NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE * NetworkParams::MAXIMUM_SNAP_SLICES, 0);
        }
    };
    $connection = new RecordingConnection(new TickHandler);

    expect(fn () => $connection->snaps()->sendItems(1, [$item]))->toThrow(RuntimeException::class, 'Snap slices limit reached');
    expect($connection->sentPackets)->toBeEmpty();
});

test('snapshot bookkeeping selects the acknowledged base and reset restores full snapshots', function () {
    $connection = new RecordingConnection(new TickHandler);
    $old        = (new ObjProjectileItem(100, 200, 1, 2, 1, 10))->setId(0);
    $current    = (new ObjProjectileItem(101, 200, 1, 2, 1, 10))->setId(0);
    $snaps      = $connection->snaps();
    $snaps->sendItems(100, [$old]);
    $snaps->setLastAckedTick(100);
    $snaps->sendItems(101, [$current]);
    $snaps->sendItems(102, [$current]);
    $snaps->setLastAckedTick(101);
    $snaps->sendItems(103, [$current]);
    $snaps->reset();
    $snaps->sendItems(104, [$current]);

    $delta    = [0, 1, 0, 2, 0, 1, 0, 0, 0, 0, 0];
    $expected = [
        [100, 101, [0, 1, 0, ...$old->encode()]],
        [101, 1, $delta],
        [102, 2, $delta],
        [103, 2, null],
        [104, 105, [0, 1, 0, ...$current->encode()]],
    ];
    expect($connection->sentPackets)->toHaveCount(count($expected));
    foreach ($expected as $index => [$tick, $distance, $payload]) {
        $packet = $connection->sentPackets[$index];
        assert($packet instanceof DefaultPacket);
        $chunk = $packet->getChunks()[0];
        expect($chunk)->toBeInstanceOf($payload === null ? SnapEmptyChunk::class : SnapSingleChunk::class);
        assert($chunk instanceof SnapEmptyChunk || $chunk instanceof SnapSingleChunk);
        expect($chunk->currentTick)->toBe($tick);
        expect($chunk->deltaTick)->toBe($distance);
        if ($chunk instanceof SnapSingleChunk) {
            expect($chunk->snapPayload)->toBe($payload);
        }
    }
});

test('snapshot checksums retain signed 32 bit wrapping', function () {
    $item = new class extends AbstractSnapItem
    {
        public function __construct()
        {
            parent::__construct(2);
            $this->setId(0);
        }

        public function getInts(): array
        {
            return [2147483647, 2147483647, 3];
        }
    };

    expect((new SnapshotEncoder)->calculateCrc([$item]))->toBe(NetworkBase::toInt32(4294967297));
    expect((new SnapshotEncoder)->calculateCrc([$item]))->toBe(1);
});
