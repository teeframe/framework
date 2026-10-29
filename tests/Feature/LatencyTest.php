<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\InputTimingChunk;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\SnapItems\ObjPlayerInfoItem;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class LatencyTestTickHandler extends TickHandler
{
    public int $now = 1_000_000_000_000;

    public function getTime(): int
    {
        return $this->now;
    }
}

class LatencyTestConnection extends ConnectionSlot
{
    /** @var AbstractPacket[] */
    public array $sentPackets = [];

    public function __construct(AbstractWorld $world, TickHandler $tickHandler)
    {
        $socket = new class extends AbstractSocket
        {
            public function __construct() {}
        };
        parent::__construct($socket, $world, $tickHandler);
    }

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        $this->sentPackets[] = $packet;

        return true;
    }
}

/** @return array{TestWorld, LatencyTestTickHandler, LatencyTestConnection} */
function createLatencyTestWorld(int $tick = 1): array
{
    resetMockServer();
    $ticks = new LatencyTestTickHandler($tick);
    $world = new TestWorld($ticks, new Map(__DIR__.'/../dm1.map'));
    $connection = new LatencyTestConnection($world, $ticks);
    $world->addTee($connection->playerTee());
    $connection->state = ConnectionSlot::STATE_INGAME;

    return [$world, $ticks, $connection];
}

function latencyTestInput(int $ackTick, int $predictionTick): InputChunk
{
    return new InputChunk(
        ackGameTick: $ackTick,
        predictionTick: $predictionTick,
        inputSize: 40,
        inputDirection: 0,
        inputTargetX: 0,
        inputTargetY: -1,
        inputJump: false,
        inputFire: 0,
        inputHook: false,
        inputPlayerFlag: GameConstants::PLAYERFLAG_PLAYING | GameConstants::PLAYERFLAG_SCOREBOARD,
        inputWantedWeapon: 0,
        inputNextWeapon: 0,
        inputPrevWeapon: 0,
    );
}

function latencyTestPlayerInfo(PlayerTee $tee, PlayerTee $requestingTee): ObjPlayerInfoItem
{
    foreach ($tee->doSnap($requestingTee) as $item) {
        if ($item instanceof ObjPlayerInfoItem) {
            return $item;
        }
    }

    throw new RuntimeException('Missing player info snapshot');
}

test('snapshot ACK latency uses elapsed send time and truncates milliseconds like 0.6', function (float $elapsed, int $expected) {
    [, $ticks, $connection] = createLatencyTestWorld();
    $snaps = $connection->snaps();
    $ticks->now += 1_000_000_000;
    $snaps->sendItems(100, []);
    $ticks->now += (int) round($elapsed * 1_000_000_000);

    $connection->feedConnection(new DefaultPacket([latencyTestInput(100, 101)], ack: 0));

    expect($snaps->getLatency())->toBe($expected);
    expect($connection->playerTee()->latency)->toBe($expected);
})->with([
    'sub-millisecond localhost ACK' => [0.0009765625, 0],
    'fractional milliseconds' => [0.015625, 15],
    'remote ACK without an artificial tick subtraction' => [0.125, 125],
]);

test('unknown snapshot ACKs leave latency unchanged and connection reset clears it', function () {
    [, $ticks, $connection] = createLatencyTestWorld();
    $snaps = $connection->snaps();
    $ticks->now += 1_000_000_000;
    $snaps->sendItems(100, []);
    $ticks->now += 15_625_000;
    $connection->feedConnection(new DefaultPacket([latencyTestInput(100, 101)], ack: 0));

    foreach ([-1, 99, 999] as $unknownTick) {
        $ticks->now += 1_000_000_000;
        $connection->feedConnection(new DefaultPacket([latencyTestInput($unknownTick, 102)], ack: 0));
        expect($snaps->getLatency())->toBe(15);
    }

    $connection->reset();
    expect($snaps->getLatency())->toBe(0);
    expect($connection->playerTee()->latency)->toBe(0);
});

test('player info reports the minimum per second instead of flashing raw ACK samples', function (bool $spectator, bool $paused) {
    [$world, $ticks, $connection] = createLatencyTestWorld();
    $tee = $connection->playerTee();
    $other = new PlayerTee;
    $world->addTee($other);
    $tee->team = $spectator ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
    $world->setPaused($paused);
    $snaps = $connection->snaps();

    // Binary-exact times avoid floating-point ambiguity at millisecond boundaries.
    $samples = [
        [1000.0, 0.0, 0],
        [1000.125, 0.01953125, 0],
        [1000.25, 0.0205078125, 0],
        [1000.984375, 0.015625, 0],
        [1001.125, 0.01953125, 0],
        [1001.25, 0.0205078125, 0],
        [1001.984375, 0.01953125, 19],
        [1002.125, 0.0751953125, 19],
        [1003.0, 0.0751953125, 75],
    ];
    foreach ($samples as $tick => [$sendTime, $elapsed, $expected]) {
        $ticks->now = (int) round($sendTime * 1_000_000_000);
        $snaps->sendItems(100 + $tick, []);
        $ticks->now += (int) round($elapsed * 1_000_000_000);
        $connection->feedConnection(new DefaultPacket([latencyTestInput(100 + $tick, 101 + $tick)], ack: 0));

        expect($snaps->getLatency())->toBe($expected);
        expect(latencyTestPlayerInfo($tee, $tee)->latency)->toBe($expected);
        expect(latencyTestPlayerInfo($tee, $other)->latency)->toBe($expected);
    }
})->with([
    'playing' => [false, false],
    'spectating' => [true, false],
    'paused world' => [false, true],
]);

test('reset clears both the reported latency and an unfinished minimum window', function () {
    [, $ticks, $connection] = createLatencyTestWorld();
    $snaps = $connection->snaps();
    $snaps->sendItems(100, []);
    $connection->feedConnection(new DefaultPacket([latencyTestInput(100, 101)], ack: 0));

    $connection->reset();
    $ticks->now += 1_000_000_000;
    $snaps->sendItems(101, []);
    $ticks->now += 125_000_000;
    $connection->feedConnection(new DefaultPacket([latencyTestInput(101, 102)], ack: 0));

    expect($snaps->getLatency())->toBe(125);
    expect(latencyTestPlayerInfo($connection->playerTee(), $connection->playerTee())->latency)->toBe(125);

    $connection->reset();
    expect($snaps->getLatency())->toBe(0);
    expect(latencyTestPlayerInfo($connection->playerTee(), $connection->playerTee())->latency)->toBe(0);
});

test('connection timing uses the injected server clock rather than the assigned world', function () {
    $serverTicks = new LatencyTestTickHandler(100);
    $worldTicks = new LatencyTestTickHandler(900);
    $worldTicks->now += 10_000_000_000;
    $world = new TestWorld($worldTicks, new Map(__DIR__.'/../dm1.map'));
    $connection = new LatencyTestConnection($world, $serverTicks);

    $serverTicks->now += 7_000_000;
    $input = latencyTestInput(-1, 101);
    $input->inputDirection = 1;
    $connection->feedConnection(new DefaultPacket([$input], ack: 0));
    $connection->chunks()->send();

    $packet = $connection->sentPackets[0];
    assert($packet instanceof DefaultPacket);
    $timing = $packet->getChunks()[0];
    assert($timing instanceof InputTimingChunk);
    expect($timing->timeLeft)->toBe(13);
    expect($connection->playerTee()->lastActionTick)->toBe(100);

    $serverTicks->now += 1_000_000_000;
    $connection->snaps()->sendItems(100, []);
    $serverTicks->now += 15_625_000;
    $connection->feedConnection(new DefaultPacket([latencyTestInput(100, 102)], ack: 0));
    expect($connection->snaps()->getLatency())->toBe(15);
    expect($connection->playerTee()->latency)->toBe(15);
});

test('input timing accounts for time within the tick and signed late inputs', function (int $elapsedNanoseconds, int $predictionTick, int $expected) {
    [, $ticks, $connection] = createLatencyTestWorld(100);
    $ticks->startTick();
    $ticks->now += $elapsedNanoseconds;

    $connection->feedConnection(new DefaultPacket([latencyTestInput(-1, $predictionTick)], ack: 0));
    $connection->chunks()->send();
    $packet = $connection->sentPackets[0];
    assert($packet instanceof DefaultPacket);
    $timing = $packet->getChunks()[0];
    assert($timing instanceof InputTimingChunk);

    expect($timing->intendedTick)->toBe($predictionTick);
    expect($timing->timeLeft)->toBe($expected);
})->with([
    'early within tick' => [7_000_000, 101, 13],
    'late within tick' => [15_000_000, 101, 5],
    'two ticks ahead' => [7_000_000, 102, 33],
    'late input' => [7_000_000, 100, -7],
    'truncate positive fractional milliseconds' => [7_750_000, 101, 12],
    'truncate negative fractional milliseconds' => [7_750_000, 100, -7],
]);

test('advancing the tick preserves its deadline rather than pretending no time has elapsed', function () {
    $ticks = new LatencyTestTickHandler(100);
    $ticks->startTick();
    $ticks->now += 7_000_000;
    $ticks->next();

    expect($ticks->get())->toBe(101);
    expect($ticks->getTimeUntilTick(101))->toBe(13);
    expect($ticks->getTimeUntilTick(102))->toBe(33);
});

test('the server records each tick start before processing worlds and snapshots', function () {
    $ticks = new LatencyTestTickHandler(100);
    $server = new class($ticks, name: 'Latency test', slotsLimit: 64) extends AbstractServerInstance
    {
        protected function boot(): void {}

        protected function selectWorldForNewConnection(): AbstractWorld
        {
            throw new RuntimeException('No connections in this test');
        }

        public function advanceTick(): void
        {
            $this->doTick();
        }
    };
    $ticks->now += 7_000_000;
    $server->advanceTick();

    expect($ticks->get())->toBe(101);
    expect($ticks->getTimeUntilTick(101))->toBe(20);
    $ticks->now += 5_000_000;
    expect($ticks->getTimeUntilTick(101))->toBe(15);
});

test('duplicate or older prediction ticks do not enqueue repeated timing corrections', function () {
    [, , $connection] = createLatencyTestWorld(100);
    foreach ([101, 101, 100, 102] as $predictionTick) {
        $connection->feedConnection(new DefaultPacket([latencyTestInput(-1, $predictionTick)], ack: 0));
    }
    $connection->chunks()->send();
    $packet = $connection->sentPackets[0];
    assert($packet instanceof DefaultPacket);
    $timings = $packet->getChunks();

    expect($timings)->toHaveCount(2);
    expect(array_map(function ($chunk): int {
        assert($chunk instanceof InputTimingChunk);

        return $chunk->intendedTick;
    }, $timings))->toBe([101, 102]);

    $connection->reset();
    $connection->feedConnection(new DefaultPacket([latencyTestInput(-1, 101)], ack: 0));
    $connection->chunks()->send();
    $packet = $connection->sentPackets[1];
    assert($packet instanceof DefaultPacket);
    expect($packet->getChunks())->toHaveCount(1);
});
