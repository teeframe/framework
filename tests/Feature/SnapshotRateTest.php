<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class SnapshotRateTestSocket extends AbstractSocket
{
    /** @var list<AbstractPacket> */
    public array $packets = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($send_data));
        $packet = PacketDecoder::decodeFromRaw($send_data);
        assert($packet instanceof AbstractPacket);
        $this->packets[] = $packet;

        return true;
    }

    /** @return list<SnapEmptyChunk|SnapSingleChunk|SnapSliceChunk> */
    public function snapshots(): array
    {
        $snapshots = [];
        foreach ($this->packets as $packet) {
            if (! $packet instanceof DefaultPacket) {
                continue;
            }
            foreach ($packet->getChunks() as $chunk) {
                if ($chunk instanceof SnapEmptyChunk || $chunk instanceof SnapSingleChunk || $chunk instanceof SnapSliceChunk) {
                    $snapshots[] = $chunk;
                }
            }
        }

        return $snapshots;
    }
}

class SnapshotRateTestWorld extends TestWorld
{
    /** @var list<array{tick: int, sounds: list<int>}> */
    public array $builtSnapshots = [];

    public function doSnap(AbstractTee $requestingTee): array
    {
        $items  = parent::doSnap($requestingTee);
        $sounds = [];
        foreach ($items as $item) {
            if ($item instanceof ObjEventSoundWorldItem) {
                $sounds[] = $item->soundId;
            }
        }
        $this->builtSnapshots[] = ['tick' => $this->getCurrentTick(), 'sounds' => $sounds];

        return $items;
    }
}

class SnapshotRateTestServer extends AbstractServerInstance
{
    public TickHandler $clock;
    public SnapshotRateTestWorld $world;

    public function __construct()
    {
        $this->clock = new TickHandler(100);
        parent::__construct($this->clock, new Config(['sv_name' => 'Snapshot rate test']));
        $this->world = $this->addWorld();
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->world;
    }

    public function addWorld(): SnapshotRateTestWorld
    {
        $world          = new SnapshotRateTestWorld($this->clock, new Map, $this);
        $this->worlds[] = $world;

        return $world;
    }

    /** @return array{ConnectionSlot, SnapshotRateTestSocket} */
    public function connect(?SnapshotRateTestWorld $world = null): array
    {
        $world ??= $this->world;
        $socket = new SnapshotRateTestSocket;
        $slot   = $this->connectionHandler->startNew($socket, $world);
        assert($slot instanceof ConnectionSlot);
        $slot->init('127.0.0.1', 8300 + count($this->connectionHandler->getConnections()));
        $slot->state = ConnectionSlot::STATE_INGAME;
        $world->addTee($slot->playerTee());
        $socket->packets = [];

        return [$slot, $socket];
    }

    public function ack(ConnectionSlot $slot, int $tick): void
    {
        expect($slot->feedConnection(new DefaultPacket([
            new InputChunk($tick, $this->clock->getCurrentTick(), 40, 0, 0, -1, false, 0, false, GameConstants::PLAYERFLAG_PLAYING, 0, 0, 0),
        ], ack: $slot->sequence)))->toBeTrue();
    }

    public function advance(int $ticks = 1): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $this->doTick();
        }
    }
}

/** @return list<int> */
function snapshotRateTicks(SnapshotRateTestWorld $world): array
{
    return array_column($world->builtSnapshots, 'tick');
}

test('INIT builds and sends five snapshots per second', function () {
    $server     = new SnapshotRateTestServer;
    [, $socket] = $server->connect();

    $server->advance(50);

    expect(snapshotRateTicks($server->world))->toBe([100, 110, 120, 130, 140]);
    expect($socket->snapshots())->toHaveCount(5);
});

test('a nonpositive snapshot ACK does not leave INIT', function (int $ackTick) {
    $server = new SnapshotRateTestServer;
    [$slot] = $server->connect();
    $server->advance();
    $server->ack($slot, $ackTick);

    $server->advance(49);

    expect(snapshotRateTicks($server->world))->toBe([100, 110, 120, 130, 140]);
})->with([-1, 0]);

test('an ACK for a retained positive snapshot enables fifty FULL snapshots per second by default', function () {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);

    $server->advance(49);

    expect(snapshotRateTicks($server->world))->toBe(range(100, 149));
    expect($socket->snapshots())->toHaveCount(50);
});

test('a missing baseline sends one full snapshot then uses RECOVER until an ACK for a retained snapshot', function (int $missingTick) {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);
    $server->ack($slot, $missingTick);

    $server->advance(2);
    expect(snapshotRateTicks($server->world))->toBe([100, 101]);
    $fallback = $socket->snapshots()[1];
    expect($fallback)->toBeInstanceOf(SnapSingleChunk::class);
    expect($fallback->currentTick - $fallback->deltaTick)->toBe(-1);
    $server->advance(47);
    expect(snapshotRateTicks($server->world))->toBe([100, 101]);
    $server->advance();
    expect(snapshotRateTicks($server->world))->toBe([100, 101, 150]);

    $server->ack($slot, 150);
    $server->advance(2);
    expect(snapshotRateTicks($server->world))->toBe([100, 101, 150, 151, 152]);
    $snapshots = $socket->snapshots();
    assert($snapshots !== []);
    $recovered = $snapshots[array_key_last($snapshots)];
    expect($recovered)->toBeInstanceOf(SnapEmptyChunk::class);
    expect($recovered->currentTick - $recovered->deltaTick)->toBe(150);
})->with(['client reset' => -1, 'missing tick' => 99, 'future tick' => 999]);

test('an expired baseline switches FULL to RECOVER without another input packet', function () {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);
    $server->advance(150);
    expect(snapshotRateTicks($server->world))->toBe(range(100, 250));

    $server->advance(49);

    expect(snapshotRateTicks($server->world))->toBe(range(100, 251));
    $server->advance();
    $snapshots = $socket->snapshots();
    assert($snapshots !== []);
    $recovery = $snapshots[array_key_last($snapshots)];
    expect($recovery->currentTick)->toBe(300);
    expect($recovery->currentTick - $recovery->deltaTick)->toBe(-1);
});

test('repeated ACKs for unsent snapshots do not leave INIT', function (int $ackTick) {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();

    for ($i = 0; $i < 50; $i++) {
        $server->ack($slot, $ackTick);
        $server->advance();
    }

    expect(snapshotRateTicks($server->world))->toBe([100, 110, 120, 130, 140]);
    expect($socket->snapshots())->toHaveCount(5);
})->with(['unsent tick' => 99, 'future tick' => 999]);

test('repeated ACKs for unavailable snapshots cannot bypass RECOVER', function (int $ackTick) {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);
    $server->advance(151);
    $server->world->builtSnapshots = [];
    $socket->packets               = [];

    for ($i = 0; $i < 50; $i++) {
        $server->ack($slot, $ackTick);
        $server->advance();
    }

    expect(snapshotRateTicks($server->world))->toBe([300]);
    $snapshots = $socket->snapshots();
    expect($snapshots)->toHaveCount(1);
    expect($snapshots[0]->currentTick - $snapshots[0]->deltaTick)->toBe(-1);

    $server->ack($slot, 300);
    $server->advance(2);
    expect(snapshotRateTicks($server->world))->toBe([300, 302, 303]);
})->with(['expired tick' => 100, 'unsent tick' => 99, 'future tick' => 999]);

test('resetting snapshots restores INIT and discards the previous baseline', function () {
    $server          = new SnapshotRateTestServer;
    [$slot, $socket] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);
    $slot->snaps()->reset();

    $server->advance(9);
    expect(snapshotRateTicks($server->world))->toBe([100]);
    $server->advance();
    expect(snapshotRateTicks($server->world))->toBe([100, 110]);
    $snapshot = $socket->snapshots()[1];
    expect($snapshot->currentTick - $snapshot->deltaTick)->toBe(-1);
});

test('snapshot-rate state is independent for clients in different worlds', function () {
    $server     = new SnapshotRateTestServer;
    $otherWorld = $server->addWorld();
    [$full]     = $server->connect();
    $server->connect($otherWorld);
    $server->advance();
    $server->ack($full, 100);

    $server->advance(10);

    expect(snapshotRateTicks($server->world))->toBe(range(100, 110));
    expect(snapshotRateTicks($otherWorld))->toBe([100, 110]);
});

test('FULL snapshots include each ticks events without replaying them on later ticks', function () {
    $server = new SnapshotRateTestServer;
    [$slot] = $server->connect();
    $server->advance();
    $server->ack($slot, 100);
    $server->world->addEvent(new ObjEventSoundWorldItem(0, 0, GameConstants::SOUND_GUN_FIRE));
    $server->advance();
    $server->world->addEvent(new ObjEventSoundWorldItem(0, 0, GameConstants::SOUND_SHOTGUN_FIRE));
    $server->advance();

    expect($server->world->builtSnapshots)->toBe([
        ['tick' => 100, 'sounds' => []],
        ['tick' => 101, 'sounds' => [GameConstants::SOUND_GUN_FIRE]],
        ['tick' => 102, 'sounds' => [GameConstants::SOUND_SHOTGUN_FIRE]],
    ]);
    $server->advance(2);
    expect(array_slice($server->world->builtSnapshots, -2))->toBe([
        ['tick' => 103, 'sounds' => []],
        ['tick' => 104, 'sounds' => []],
    ]);
});
