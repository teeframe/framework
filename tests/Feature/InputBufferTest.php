<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\InputTimingChunk;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class InputBufferTestCharacter extends PvpCharacterEntity
{
    /** @var list<PlayerInput> */
    public array $receivedInputs  = [];
    public int $inputApplications = 0;

    public function onPredictedInput(PlayerInput $newInput): void
    {
        $this->receivedInputs[] = $newInput;
        parent::onPredictedInput($newInput);
    }

    public function applyInput(): void
    {
        $this->inputApplications++;
        parent::applyInput();
    }
}

class InputBufferTestConnection extends ConnectionSlot
{
    /** @var list<AbstractPacket> */
    public array $packets = [];

    public function __construct(AbstractWorld $world, TickHandler $clock)
    {
        $socket = new class extends AbstractSocket
        {
            public function __construct() {}
        };
        parent::__construct($socket, $world, $clock);
    }

    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        $this->packets[] = $packet;

        return true;
    }
}

/** @return array{TestWorld, TickHandler, InputBufferTestConnection, InputBufferTestCharacter|null} */
function createInputBufferWorld(bool $alive = true): array
{
    $clock       = new TickHandler(100);
    $world       = new TestWorld($clock, new Map);
    $slot        = new InputBufferTestConnection($world, $clock);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $world->addTee($slot->playerTee());
    $slot->playerTee()->spawning = false;
    $character                   = null;
    if ($alive) {
        $character = new InputBufferTestCharacter($world, new Vector2(64, 64));
        $character->spawn(new Vector2(64, 64), $slot->playerTee());
        $world->addEntity($character);
    }

    return [$world, $clock, $slot, $character];
}

function bufferedTestInput(int $predictionTick, int $direction = 1, int $fire = 0, int $ackTick = -1): InputChunk
{
    return new InputChunk($ackTick, $predictionTick, 40, $direction, 99, 1, false, $fire, false, GameConstants::PLAYERFLAG_PLAYING, 0, 0, 0);
}

test('network inputs schedule late ticks for the next server tick without changing the timing echo', function (int $predictionTick, int $scheduledTick) {
    [$world, $clock, $slot, $character] = createInputBufferWorld();
    assert($character instanceof InputBufferTestCharacter);
    $world->doTick();
    $clock->next(); // Tick 100 is complete; 101 is the next unprocessed tick.
    $slot->feedConnection(new DefaultPacket([bufferedTestInput($predictionTick)], ack: $slot->sequence));
    $slot->chunks()->send();

    expect(array_keys($slot->playerTee()->inputs))->toBe([$scheduledTick]);
    if ($predictionTick > 0) {
        $packet = $slot->packets[0];
        assert($packet instanceof DefaultPacket);
        $timing = $packet->getChunks()[0];
        assert($timing instanceof InputTimingChunk);
        expect($timing->intendedTick)->toBe($predictionTick);
    }
    expect($character->receivedInputs)->toBeEmpty();
    $world->doTick();
    expect($character->receivedInputs)->toHaveCount($scheduledTick === 101 ? 1 : 0);
    if ($scheduledTick === 101) {
        expect($character->receivedInputs[0]->direction)->toBe(1);
        expect($slot->playerTee()->inputs)->toBeEmpty();
    }
})->with([
    'negative tick'         => [-100, 101],
    'tick zero'             => [0, 101],
    'late input'            => [99, 101],
    'last completed tick'   => [100, 101],
    'next unprocessed tick' => [101, 101],
    'future tick'           => [102, 102],
    'distant future'        => [2_147_483_647, 2_147_483_647],
]);

test('network input buffering retains at most the latest 200 distinct scheduled ticks', function () {
    [, , $slot] = createInputBufferWorld();
    for ($i = 0; $i < 1000; $i++) {
        $slot->feedConnection(new DefaultPacket([bufferedTestInput(1_000_000 + $i)], ack: $slot->sequence));
        expect(count($slot->playerTee()->inputs))->toBeLessThanOrEqual(200);
    }

    expect(array_keys($slot->playerTee()->inputs))->toBe(range(1_000_800, 1_000_999));
});

test('duplicate scheduled ticks replace their input and refresh their place in the bounded buffer', function () {
    [, , $slot] = createInputBufferWorld();
    for ($tick = 1000; $tick < 1200; $tick++) {
        $slot->feedConnection(new DefaultPacket([bufferedTestInput($tick)], ack: $slot->sequence));
    }
    $slot->feedConnection(new DefaultPacket([bufferedTestInput(1000, -1)], ack: $slot->sequence));
    $slot->feedConnection(new DefaultPacket([bufferedTestInput(1200)], ack: $slot->sequence));

    $inputs = $slot->playerTee()->inputs;
    expect($inputs)->toHaveCount(200);
    expect($inputs[1000]->direction)->toBe(-1);
    expect(isset($inputs[1001]))->toBeFalse();
    expect(isset($inputs[1200]))->toBeTrue();
});

test('multiple late inputs coalesce to the latest received frame for the next tick', function () {
    [$world, $clock, $slot, $character] = createInputBufferWorld();
    assert($character instanceof InputBufferTestCharacter);
    $world->doTick();
    $clock->next();
    foreach ([99 => 1, 100 => 0, 98 => -1] as $tick => $direction) {
        $slot->feedConnection(new DefaultPacket([bufferedTestInput($tick, $direction)], ack: $slot->sequence));
    }
    $world->doTick();

    expect($slot->playerTee()->inputs)->toBeEmpty();
    expect($character->receivedInputs)->toHaveCount(1);
    expect($character->receivedInputs[0]->direction)->toBe(-1);
});

test('out of order buffered inputs consume the newest due tick and remove every older entry', function () {
    [$world, $clock, $slot, $character] = createInputBufferWorld();
    assert($character instanceof InputBufferTestCharacter);
    foreach ([105 => 1, 103 => 0, 101 => -1] as $tick => $direction) {
        $slot->feedConnection(new DefaultPacket([bufferedTestInput($tick, $direction)], ack: $slot->sequence));
    }
    for ($i = 0; $i < 4; $i++) {
        $clock->next();
    }
    $world->doTick();

    expect($character->receivedInputs)->toHaveCount(1);
    expect($character->receivedInputs[0]->direction)->toBe(0);
    expect(array_keys($slot->playerTee()->inputs))->toBe([105]);
    $clock->next();
    $world->doTick();
    expect($character->receivedInputs)->toHaveCount(2);
    expect($character->receivedInputs[1]->direction)->toBe(1);
    expect($slot->playerTee()->inputs)->toBeEmpty();
});

test('dead and spectator inputs are drained without replay after respawn even while paused', function (bool $spectator, bool $paused, int $fire) {
    [$world, $clock, $slot] = createInputBufferWorld(alive: false);
    $tee                    = $slot->playerTee();
    $tee->team              = $spectator ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
    $tee->respawnTick       = 500;
    $tee->inputs            = [90 => input(['fire' => 1]), 100 => input(['fire' => $fire]), 101 => input(['direction' => -1])];
    $world->setPaused($paused);
    $world->doTick();

    expect(array_keys($tee->inputs))->toBe([101]);
    expect($tee->spawning)->toBe(! $spectator && ($fire & 1) === 1);
    $clock->next();
    $world->doTick();
    expect($tee->inputs)->toBeEmpty();
    expect($tee->character)->toBeNull();

    $tee->team = GameConstants::TEAM_RED;
    $character = new InputBufferTestCharacter($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $tee);
    $world->addEntity($character);
    $world->setPaused(false);
    $clock->next();
    $world->doTick();
    expect($character->receivedInputs)->toBeEmpty();
})->with([false, true])->with([false, true])->with([0, 1]);

test('a dead player can request respawn with a late fire input without retaining it', function () {
    [$world, $clock, $slot] = createInputBufferWorld(alive: false);
    $tee                    = $slot->playerTee();
    $tee->respawnTick       = 500;
    $world->doTick();
    $clock->next();
    $slot->feedConnection(new DefaultPacket([bufferedTestInput(99, fire: 1)], ack: $slot->sequence));
    $world->doTick();

    expect($tee->spawning)->toBeTrue();
    expect($tee->character)->toBeNull();
    expect($tee->inputs)->toBeEmpty();
});

test('connection resets and world changes start with empty input buffers', function (bool $transfer) {
    [$world, $clock, $slot] = createInputBufferWorld();
    $slot->feedConnection(new DefaultPacket([bufferedTestInput(102)], ack: $slot->sequence));
    expect($slot->playerTee()->inputs)->not->toBeEmpty();

    if ($transfer) {
        $slot->changeWorld(new TestWorld($clock, new Map));
    } else {
        $slot->reset();
    }

    expect($slot->playerTee()->inputs)->toBeEmpty();
})->with([false, true]);
