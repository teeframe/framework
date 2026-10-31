<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;
use Tests\Support\RecordingGameServer;

class CharacterPredictionTestServer extends RecordingGameServer
{
    public function advance(AbstractWorld $world): void
    {
        $this->worlds = [$world];
        $this->doTick();
    }
}

/** @return array{CharacterPredictionTestServer, TestWorld, TickHandler, ConnectionSlot, PvpCharacterEntity} */
function createCharacterPredictionWorld(int $tick): array
{
    $clock  = new TickHandler($tick);
    $server = new CharacterPredictionTestServer($clock);
    $map    = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(32, 32, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 32 * 32));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $world = new TestWorld($clock, $map, $server);
    // Isolate input scheduling from rounding, ground contact and other physics differences.
    $world->getTuneController()->gravity = 0;
    $socket                              = new class extends AbstractSocket
    {
        public function __construct() {}
    };
    $slot = new class($socket, $world, $clock) extends ConnectionSlot
    {
        protected function handlePacketSending(AbstractPacket $packet): bool
        {
            return true;
        }
    };
    $slot->init('127.0.0.1', 8300);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $world->addTee($slot->playerTee());
    $character = new PvpCharacterEntity($world, new Vector2(128, 128));
    $character->spawn(new Vector2(128, 128), $slot->playerTee());
    $world->addEntity($character);

    return [$server, $world, $clock, $slot, $character];
}

function characterPredictionSnapshot(PvpCharacterEntity $character): ObjCharacterItem
{
    $snapshot = $character->doSnap(new PlayerTee)[0];
    assert($snapshot instanceof ObjCharacterItem);

    return $snapshot;
}

/** Predict one isolated character-core frame, without server-side weapon mechanics. */
function predictCharacterCoreFrame(AbstractWorld $world, ObjCharacterItem $snapshot, PlayerInput $input): ObjCharacterItem
{
    $prediction = new PvpCharacterEntity($world, new Vector2($snapshot->x, $snapshot->y));
    $prediction->spawn(new Vector2($snapshot->x, $snapshot->y));
    $prediction->vel    = new Vector2($snapshot->velX / 256.0, $snapshot->velY / 256.0);
    $prediction->jumped = $snapshot->jumped;
    $prediction->tick   = $snapshot->tick + 1;
    $collision          = $world->getMap()->getCollision();
    assert($collision !== null);
    // Client prediction replaces its own core; it must not collide with the server copy.
    $prediction->tick($input->direction, $input->targetX, $input->targetY, $input->jump, $input->hook, $collision, $world->getTuneController(), []);
    $prediction->move($collision, $world->getTuneController());

    return characterPredictionSnapshot($prediction);
}

function sendCharacterPredictionInput(ConnectionSlot $slot, int $tick, PlayerInput $input): void
{
    $slot->feedConnection(new DefaultPacket([
        new InputChunk(
            ackGameTick: -1,
            predictionTick: $tick,
            inputSize: 40,
            inputDirection: $input->direction,
            inputTargetX: $input->targetX,
            inputTargetY: $input->targetY,
            inputJump: $input->jump,
            inputFire: $input->fire,
            inputHook: $input->hook,
            inputPlayerFlag: $input->playerFlags,
            inputWantedWeapon: $input->wantedWeapon,
            inputNextWeapon: $input->nextWeapon,
            inputPrevWeapon: $input->prevWeapon,
        ),
    ], ack: $slot->sequence));
}

test('jump inputs use the next unprocessed tick without moving future inputs earlier', function (int $offset) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $server->advance($world);
    $requestedTick = $clock->get() + $offset;
    $scheduledTick = max($requestedTick, 101);
    $slot->feedConnection(new DefaultPacket([
        new InputChunk(100, $requestedTick, 40, 0, 100, 0, true, 0, false, GameConstants::PLAYERFLAG_PLAYING, 0, 0, 0),
    ], ack: $slot->sequence));
    expect(array_keys($slot->playerTee()->inputs))->toBe([$scheduledTick]);

    for ($tick = 101; $tick <= $scheduledTick; $tick++) {
        $server->advance($world);
        $snapshot = characterPredictionSnapshot($character);
        expect($snapshot->tick)->toBe($tick);
        expect($snapshot->jumped & 2)->toBe($tick === $scheduledTick ? 2 : 0);
        expect($snapshot->y)->toBe($tick === $scheduledTick ? 116 : 128);
    }
    expect($slot->playerTee()->inputs)->toBeEmpty();
})->with(['late' => -20, 'last completed tick' => -1, 'on time' => 0, 'one tick ahead' => 1, 'three ticks ahead' => 3]);

test('an on time air jump is not replayed as a second client effect after the next snapshot', function (int $startTick) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld($startTick);
    $server->advance($world);
    $before = characterPredictionSnapshot($character);
    expect($clock->get())->toBe($startTick + 1);
    expect($before->jumped & 2)->toBe(0);

    // The local client predicts its jump on the next tick before the server confirms it.
    $jumpInput     = input(['targetX' => 100, 'targetY' => 0, 'jump' => true]);
    $predictedJump = predictCharacterCoreFrame($world, $before, $jumpInput);
    $effects       = ! ($before->jumped & 2) && ($predictedJump->jumped & 2) ? 1 : 0;
    expect($effects)->toBe(1);
    $slot->feedConnection(new DefaultPacket([
        new InputChunk($startTick, $startTick + 1, 40, 0, 100, 0, true, 0, false, GameConstants::PLAYERFLAG_PLAYING, 0, 0, 0),
    ], ack: $slot->sequence));
    $server->advance($world);
    $confirmedJump = characterPredictionSnapshot($character);

    // CPlayers::RenderPlayer detects the effect from the 0 -> 2 jumped-bit transition.
    // A snapshot that delays the jump lets the client predict that transition again.
    $repredicted = predictCharacterCoreFrame($world, $confirmedJump, $jumpInput);
    if (! ($confirmedJump->jumped & 2) && ($repredicted->jumped & 2)) {
        $effects++;
    }
    expect($effects)->toBe(1);
    expect($confirmedJump->tick)->toBe($startTick + 1);
    expect($confirmedJump->jumped & 2)->toBe(2);
    expect([$confirmedJump->x, $confirmedJump->y, $confirmedJump->velY])
        ->toBe([$predictedJump->x, $predictedJump->y, $predictedJump->velY]);

    $server->advance($world);
    $heldJump = characterPredictionSnapshot($character);
    expect([$heldJump->x, $heldJump->y, $heldJump->velY, $heldJump->jumped])
        ->toBe([$repredicted->x, $repredicted->y, $repredicted->velY, $repredicted->jumped]);
    expect($character->triggeredEvents & GameConstants::COREEVENT_AIR_JUMP)->toBe(0);
})->with([100, 1_000_000]);

test('ninja activation uses the scheduled input tick without replaying a held fire press', function (int $offset) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->giveNinja();
    $idle = input(['targetX' => 100, 'targetY' => 0]);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $idle);
        $server->advance($world);
    }
    $requestedTick = $clock->get() + $offset;
    $attackTick    = max($requestedTick, $clock->get());
    $fire          = input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]);
    sendCharacterPredictionInput($slot, $requestedTick, $fire);
    for ($tick = $clock->get(); $tick <= $attackTick; $tick++) {
        $server->advance($world);
        $snapshot = characterPredictionSnapshot($character);
        expect($snapshot->attackTick)->toBe($tick === $attackTick ? $attackTick : 0);
    }
    expect($character->ninjaCurrentMoveTime)->toBe(10);
    expect($character->getPosition()->x)->toEqualWithDelta(128.0, 0.00000001);
    expect(characterPredictionSnapshot($character)->weapon)->toBe(GameConstants::WEAPON_NINJA);

    // Keep holding beyond the 40-tick cooldown: ninja must not auto-fire again.
    for ($i = 0; $i < 45; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $fire);
        $server->advance($world);
        expect(characterPredictionSnapshot($character)->attackTick)->toBe($attackTick);
    }
    expect($character->reloadTimer)->toBe(0);
    expect($character->getPosition()->x)->toEqualWithDelta(578.0, 0.00000001);
    expect($character->vel->x)->toBe(0.0);
    expect($character->vel->y)->toBe(0.0);
})->with(['late fire input' => -1, 'on time fire input' => 0, 'future fire input' => 2]);

test('ninja dash snapshots do not make core prediction extrapolate the server driven dash', function (int $targetX, int $targetY) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->giveNinja();
    $idle = input(['targetX' => $targetX, 'targetY' => $targetY]);
    $fire = input(['targetX' => $targetX, 'targetY' => $targetY, 'fire' => 1]);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $idle);
        $server->advance($world);
    }
    $attackTick = $clock->get();
    sendCharacterPredictionInput($slot, $attackTick, $fire);
    $server->advance($world);

    // HandleNinja decrements the ten-tick timer before moving (0.6 character.cpp).
    for ($moveTime = 9; $moveTime > 0; $moveTime--) {
        $previousPosition = clone $character->getPosition();
        sendCharacterPredictionInput($slot, $clock->get(), $fire);
        $server->advance($world);
        $snapshot = characterPredictionSnapshot($character);
        expect($character->ninjaCurrentMoveTime)->toBe($moveTime);
        expect($character->getPosition()->distance($previousPosition))->toEqualWithDelta(50.0, 0.00000001);
        expect([$snapshot->velX, $snapshot->velY])->toBe([0, 0]);
        expect($snapshot->weapon)->toBe(GameConstants::WEAPON_NINJA);
        expect($snapshot->attackTick)->toBe($attackTick);

        // Vanilla predicts only CCharacterCore, not HandleNinja. Its position can
        // advance on the next server snapshot, but must not invent another dash.
        $predicted = $snapshot;
        for ($ahead = 1; $ahead <= 6; $ahead++) {
            $predicted = predictCharacterCoreFrame($world, $predicted, $fire);
            expect([$predicted->x, $predicted->y])->toBe([$snapshot->x, $snapshot->y]);
            expect([$predicted->velX, $predicted->velY])->toBe([0, 0]);
        }
    }
    $lastDash = $snapshot;
    sendCharacterPredictionInput($slot, $clock->get(), $fire);
    $server->advance($world);
    $finished  = characterPredictionSnapshot($character);
    $predicted = predictCharacterCoreFrame($world, $lastDash, $fire);
    expect($character->ninjaCurrentMoveTime)->toBe(0);
    expect([$finished->x, $finished->y, $finished->velX, $finished->velY])
        ->toBe([$predicted->x, $predicted->y, $predicted->velX, $predicted->velY]);
})->with(['right' => [100, 0], 'left' => [-100, 0], 'up' => [0, -100], 'diagonal' => [100, -100]]);

test('normal core prediction resumes from the speed restored after a ninja dash', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $world->getTuneController()->airFriction     = 100;
    $character->giveNinja();
    $idle = input(['targetX' => 100, 'targetY' => 0]);
    $fire = input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $idle);
        $server->advance($world);
    }
    $character->vel = new Vector2(3, 4);
    sendCharacterPredictionInput($slot, $clock->get(), $fire);
    $server->advance($world);
    expect($character->ninjaOldVelAmount)->toBe(5.0);
    for ($i = 0; $i < 10; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $fire);
        $server->advance($world);
    }
    $restored = characterPredictionSnapshot($character);
    expect($character->ninjaCurrentMoveTime)->toBe(0);
    expect([$restored->velX, $restored->velY])->toBe([5 * 256, 0]);

    $predicted = $restored;
    for ($i = 0; $i < 6; $i++) {
        $predicted = predictCharacterCoreFrame($world, $predicted, $fire);
        sendCharacterPredictionInput($slot, $clock->get(), $fire);
        $server->advance($world);
        $actual = characterPredictionSnapshot($character);
        expect([$actual->x, $actual->y, $actual->velX, $actual->velY])
            ->toBe([$predicted->x, $predicted->y, $predicted->velX, $predicted->velY]);
    }
});
