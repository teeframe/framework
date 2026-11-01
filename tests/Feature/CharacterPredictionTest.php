<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
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
    // Keep input-scheduling tests airborne without vertical movement.
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
    $prediction->move($collision, $world->getTuneController(), []);

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

/** @return list<PlayerInput> */
function characterPredictionInputStates(PvpCharacterEntity $character): array
{
    return array_map(function (string $property) use ($character): PlayerInput {
        $input = (new ReflectionProperty($character, $property))->getValue($character);
        assert($input instanceof PlayerInput);

        return $input;
    }, ['input', 'latestInput', 'latestPrevInput']);
}

test('gun fire uses the packet arrival tick and latest aim while movement waits for its prediction tick', function (int $offset) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input(['targetX' => 100, 'targetY' => 0]));
        $server->advance($world);
    }
    $arrivalTick    = $clock->get();
    $predictionTick = $arrivalTick + $offset;
    sendCharacterPredictionInput($slot, $predictionTick, input([
        'direction' => 1,
        'targetX'   => 3,
        'targetY'   => -4,
        'fire'      => 1,
    ]));

    expect($character->attackTick)->toBe($arrivalTick);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
    expect($character->getPosition())->toEqual(new Vector2(128, 128));
    $projectiles = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($projectiles)->toHaveCount(1);
    expect($projectiles[0]->direction->x)->toEqualWithDelta(0.6, 0.000000001);
    expect($projectiles[0]->direction->y)->toEqualWithDelta(-0.8, 0.000000001);
    expect($projectiles[0]->getPosition()->x)->toEqualWithDelta(128 + 0.6 * 21, 0.000000001);
    expect($projectiles[0]->getPosition()->y)->toEqualWithDelta(128 - 0.8 * 21, 0.000000001);
    expect($projectiles[0]->doSnap($slot->playerTee())[0]->getInts()[5])->toBe($arrivalTick);

    $server->advance($world);
    expect(characterPredictionSnapshot($character)->direction)->toBe($offset > 0 ? 0 : 1);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
})->with(['late' => -10, 'current tick' => 0, 'future tick' => 10]);

test('direct-fired bullets cannot hit behind the muzzle on their creation tick', function (int $weapon) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input(['targetX' => 100, 'targetY' => 0]));
        $server->advance($world);
    }
    $world->getTuneController()->playerCollision = 0;
    $character->giveWeapon($weapon, 10);
    $character->setWeapon($weapon);
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);
    $target = new PvpCharacterEntity($world, new Vector2(80, 128));
    $target->spawn(new Vector2(80, 128), $targetTee);
    $world->addEntity($target);
    $arrivalTick = $clock->get();

    sendCharacterPredictionInput($slot, $arrivalTick, input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]));
    $projectiles = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($projectiles)->toHaveCount($weapon === GameConstants::WEAPON_SHOTGUN ? 5 : 1);
    foreach ($projectiles as $projectile) {
        expect($projectile->getPosition()->x)->toBe(149.0);
        expect($projectile->doSnap($slot->playerTee())[0]->getInts()[5])->toBe($arrivalTick);
    }

    $server->advance($world);
    expect($target->health)->toBe(10);
    foreach ($projectiles as $projectile) {
        expect($projectile->isToDestroy())->toBeFalse();
        expect($projectile->getPosition()->x)->toBe(149.0);
    }

    $server->advance($world);
    expect($target->health)->toBe(10);
    foreach ($projectiles as $projectile) {
        expect($projectile->isToDestroy())->toBeFalse();
        expect($projectile->getPosition()->x)->toBeGreaterThan(149.0);
    }
})->with([GameConstants::WEAPON_GUN, GameConstants::WEAPON_SHOTGUN]);

test('a direct-fired bullet hits forward targets on its first nonnegative trajectory segment', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input(['targetX' => 100, 'targetY' => 0]));
        $server->advance($world);
    }
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);
    $target = new PvpCharacterEntity($world, new Vector2(190, 128));
    $target->spawn(new Vector2(190, 128), $targetTee);
    $world->addEntity($target);
    sendCharacterPredictionInput($slot, $clock->get(), input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]));

    $server->advance($world);
    expect($target->health)->toBe(10);
    $server->advance($world);
    expect($target->health)->toBe(9);
});

test('direct gun fire waits for three predicted inputs and consumes earlier presses', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input(['fire' => 1 + $i * 2]));
        $server->advance($world);
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(10);
    }
    sendCharacterPredictionInput($slot, $clock->get(), input(['fire' => 5]));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(10);
    sendCharacterPredictionInput($slot, $clock->get(), input(['fire' => 7]));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
});

test('direct fire normalizes a zero aim target before the next physics tick', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input(['targetX' => 100, 'targetY' => 0]));
        $server->advance($world);
    }
    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['targetX' => 0, 'targetY' => 0, 'fire' => 1]));

    $projectiles = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($projectiles)->toHaveCount(1);
    expect($projectiles[0]->direction)->toEqual(new Vector2(0, -1));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
});

test('one direct input can switch weapons and hit with the hammer before the next tick', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    $victimTee = new PlayerTee;
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2(160, 128));
    $victim->spawn(new Vector2(160, 128), $victimTee);
    $world->addEntity($victim);

    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['targetX' => 100, 'targetY' => 0, 'wantedWeapon' => 1, 'fire' => 1]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->attackTick)->toBe($clock->get());
    expect($victim->health)->toBe(7);
});

test('weapon switching happens on receipt and buffered wheel counters are not replayed', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 10);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    $predictionTick = $clock->get() + 10;
    sendCharacterPredictionInput($slot, $predictionTick, input(['nextWeapon' => 1]));
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_SHOTGUN);
    for ($i = 0; $i < 15; $i++) {
        $server->advance($world);
        expect($character->activeWeapon)->toBe(GameConstants::WEAPON_SHOTGUN);
    }

    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['nextWeapon' => 1, 'prevWeapon' => 1]));
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['nextWeapon' => 1, 'prevWeapon' => 1, 'wantedWeapon' => 1]));
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('a gun fire press is not replayed by ticks repeated packets or its buffered input', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    $arrivalTick = $clock->get();
    $fire        = input(['fire' => 1]);
    sendCharacterPredictionInput($slot, $arrivalTick + 10, $fire);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
    $reloadTimer = $character->reloadTimer;
    for ($i = 0; $i < 5; $i++) {
        sendCharacterPredictionInput($slot, $arrivalTick + 10, $fire);
        expect($character->reloadTimer)->toBe($reloadTimer);
    }
    for ($i = 0; $i < 15; $i++) {
        $server->advance($world);
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
        expect($character->attackTick)->toBe($arrivalTick);
    }
    sendCharacterPredictionInput($slot, $clock->get() + 10, $fire);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);

    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['fire' => 2]));
    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['fire' => 3]));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(8);
    expect($character->attackTick)->toBe($clock->get());
});

test('a fire press received during cooldown is consumed instead of firing later', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    sendCharacterPredictionInput($slot, $clock->get() + 10, input(['fire' => 1]));
    sendCharacterPredictionInput($slot, $clock->get() + 11, input(['fire' => 3]));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
    for ($i = 0; $i < 15; $i++) {
        $server->advance($world);
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(9);
    }
});

test('automatic fire continues from direct input and an older buffered hold cannot undo a release', function (int $weapon, int $cooldown) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->giveWeapon($weapon, 10);
    $character->setWeapon($weapon);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    sendCharacterPredictionInput($slot, $clock->get() + 100, input(['fire' => 1]));
    expect($character->weapons[$weapon]->ammo)->toBe(9);
    for ($i = 0; $i < $cooldown; $i++) {
        $server->advance($world);
        expect($character->weapons[$weapon]->ammo)->toBe(9);
    }
    $server->advance($world);
    expect($character->weapons[$weapon]->ammo)->toBe(8);

    sendCharacterPredictionInput($slot, $clock->get() + 1, input(['fire' => 2]));
    for ($i = 0; $i < 110; $i++) {
        $server->advance($world);
        expect($character->weapons[$weapon]->ammo)->toBe(8);
    }
})->with([
    'shotgun' => [GameConstants::WEAPON_SHOTGUN, 25],
    'grenade' => [GameConstants::WEAPON_GRENADE, 25],
    'rifle'   => [GameConstants::WEAPON_RIFLE, 40],
]);

test('direct weapon input does not act while the world is paused', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    $world->setPaused(true);
    sendCharacterPredictionInput($slot, $clock->get(), input(['wantedWeapon' => 1, 'fire' => 1]));
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->attackTick)->toBe(0);
    expect($character->reloadTimer)->toBe(0);
});

test('received player flags appear in character snapshots before their prediction tick', function (int $flags) {
    [, $world, , $slot, $character] = createCharacterPredictionWorld(100);

    sendCharacterPredictionInput($slot, 150, input(['playerFlags' => $flags]));

    expect(characterPredictionSnapshot($character)->playerFlags)->toBe($flags);
    expect(array_keys($slot->playerTee()->inputs))->toBe([150]);
    $character->die();
    $replacement = new PvpCharacterEntity($world, new Vector2(128, 128));
    $replacement->spawn(new Vector2(128, 128), $slot->playerTee());
    expect(characterPredictionSnapshot($replacement)->playerFlags)->toBe($flags);
})->with([
    'playing'    => GameConstants::PLAYERFLAG_PLAYING,
    'menu'       => GameConstants::PLAYERFLAG_IN_MENU,
    'scoreboard' => GameConstants::PLAYERFLAG_PLAYING | GameConstants::PLAYERFLAG_SCOREBOARD,
    'chatting'   => GameConstants::PLAYERFLAG_CHATTING,
]);

test('opening chat releases controls and continuing chat input is ignored', function (int $fire, int $releasedFire) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input());
        $server->advance($world);
    }
    $held = input([
        'direction'    => 1,
        'targetX'      => 100,
        'targetY'      => 0,
        'jump'         => true,
        'fire'         => $fire,
        'hook'         => true,
        'playerFlags'  => GameConstants::PLAYERFLAG_PLAYING,
        'wantedWeapon' => 2,
    ]);
    sendCharacterPredictionInput($slot, $clock->get(), $held);
    $server->advance($world);
    $ammo           = $character->weapons[GameConstants::WEAPON_GUN]->ammo;
    $lastActionTick = $slot->playerTee()->lastActionTick;
    $reset          = input([
        'targetX'      => 100,
        'targetY'      => 0,
        'fire'         => $releasedFire,
        'playerFlags'  => GameConstants::PLAYERFLAG_PLAYING,
        'wantedWeapon' => 2,
    ]);
    $chat = input([
        'direction'    => -1,
        'targetX'      => -50,
        'targetY'      => 17,
        'jump'         => true,
        'fire'         => 1,
        'hook'         => true,
        'playerFlags'  => GameConstants::PLAYERFLAG_CHATTING,
        'wantedWeapon' => 1,
        'nextWeapon'   => 1,
    ]);

    sendCharacterPredictionInput($slot, $clock->get(), $chat);

    expect(characterPredictionInputStates($character))->toEqual([$reset, $reset, $reset]);
    expect(characterPredictionSnapshot($character)->playerFlags)->toBe(GameConstants::PLAYERFLAG_CHATTING);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), input([
            'direction'    => -1,
            'jump'         => true,
            'fire'         => 3,
            'hook'         => true,
            'playerFlags'  => GameConstants::PLAYERFLAG_CHATTING | GameConstants::PLAYERFLAG_SCOREBOARD,
            'wantedWeapon' => 1,
            'nextWeapon'   => 3,
        ]));
        $server->advance($world);
        expect(characterPredictionInputStates($character))->toEqual([$reset, $reset, $reset]);
        expect(characterPredictionSnapshot($character)->playerFlags)->toBe(GameConstants::PLAYERFLAG_CHATTING);
        expect(characterPredictionSnapshot($character)->direction)->toBe(0);
        expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe($ammo);
        expect($slot->playerTee()->lastActionTick)->toBe($lastActionTick);
    }

    $resumed = input(['direction' => -1, 'targetX' => 100, 'targetY' => 0, 'fire' => $releasedFire, 'playerFlags' => GameConstants::PLAYERFLAG_PLAYING]);
    sendCharacterPredictionInput($slot, $clock->get(), $resumed);
    $server->advance($world);
    expect(characterPredictionInputStates($character)[0])->toEqual($resumed);
    expect(characterPredictionSnapshot($character)->direction)->toBe(-1);
    expect(characterPredictionSnapshot($character)->playerFlags)->toBe(GameConstants::PLAYERFLAG_PLAYING);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe($ammo);
    expect($slot->playerTee()->lastActionTick)->toBeGreaterThan($lastActionTick);
})->with(['pressed' => [1, 2], 'pressed at rollover' => [63, 0], 'released' => [0, 0], 'released near rollover' => [62, 62]]);

test('chatting fire input does not request a dead player respawn', function () {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->die();
    $tee              = $slot->playerTee();
    $tee->spawning    = false;
    $tee->respawnTick = 500;
    $lastActionTick   = $tee->lastActionTick;

    sendCharacterPredictionInput($slot, $clock->get(), input(['fire' => 1, 'playerFlags' => GameConstants::PLAYERFLAG_CHATTING]));
    $server->advance($world);

    expect($tee->spawning)->toBeFalse();
    expect($tee->character)->toBeNull();
    expect($tee->inputs)->toBeEmpty();
    expect($tee->lastActionTick)->toBe($lastActionTick);

    sendCharacterPredictionInput($slot, $clock->get(), input(['fire' => 1, 'playerFlags' => GameConstants::PLAYERFLAG_PLAYING]));
    $server->advance($world);
    expect($tee->spawning)->toBeTrue();
});

test('successive server ticks match prediction from the rounded wire state', function (int $direction, bool $jump) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $world->getTuneController()->gravity         = 50;
    $character->vel                              = new Vector2(0.3, 0.7);
    $frame                                       = input(['direction' => $direction, 'targetX' => 100, 'targetY' => 0, 'jump' => $jump]);
    $predicted                                   = characterPredictionSnapshot($character);
    // Start the server and predictor from identical wire-representable velocities.
    $character->vel = new Vector2($predicted->velX / 256.0, $predicted->velY / 256.0);
    for ($i = 0; $i < 30; $i++) {
        $predicted = predictCharacterCoreFrame($world, $predicted, $frame);
        sendCharacterPredictionInput($slot, $clock->get(), $frame);
        $server->advance($world);
        $actual = characterPredictionSnapshot($character);
        expect([$actual->x, $actual->y, $actual->velX, $actual->velY, $actual->jumped])
            ->toBe([$predicted->x, $predicted->y, $predicted->velX, $predicted->velY, $predicted->jumped]);
    }
})->with(['idle' => [0, false], 'walking' => [1, false], 'air jump' => [-1, true]]);

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

test('ninja activates on receipt regardless of the prediction tick without replaying held fire', function (int $offset) {
    [$server, $world, $clock, $slot, $character] = createCharacterPredictionWorld(100);
    $character->giveNinja();
    $idle = input(['targetX' => 100, 'targetY' => 0]);
    for ($i = 0; $i < 3; $i++) {
        sendCharacterPredictionInput($slot, $clock->get(), $idle);
        $server->advance($world);
    }
    $requestedTick = $clock->get() + $offset;
    $attackTick    = $clock->get();
    $fire          = input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]);
    sendCharacterPredictionInput($slot, $requestedTick, $fire);
    expect(characterPredictionSnapshot($character)->attackTick)->toBe($attackTick);
    expect($character->ninjaCurrentMoveTime)->toBe(10);
    expect($character->getPosition()->x)->toEqualWithDelta(128.0, 0.00000001);
    expect(characterPredictionSnapshot($character)->weapon)->toBe(GameConstants::WEAPON_NINJA);
    for ($i = 0; $i < 5; $i++) {
        sendCharacterPredictionInput($slot, $requestedTick, $fire);
        expect($character->ninjaCurrentMoveTime)->toBe(10);
        expect($character->reloadTimer)->toBe(40);
        expect($character->getPosition())->toEqual(new Vector2(128, 128));
    }

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

    $dashStep = (new Vector2($targetX, $targetY))->normalize()->mul(50);
    // HandleNinja decrements the ten-tick timer before moving (0.6 character.cpp).
    for ($moveTime = 9; $moveTime > 0; $moveTime--) {
        $previousPosition = clone $character->getPosition();
        sendCharacterPredictionInput($slot, $clock->get(), $fire);
        $server->advance($world);
        $snapshot = characterPredictionSnapshot($character);
        expect($character->ninjaCurrentMoveTime)->toBe($moveTime);
        // The deferred pass rounds diagonal dash endpoints too.
        expect($character->getPosition())->toEqual(new Vector2(
            round($previousPosition->x + $dashStep->x),
            round($previousPosition->y + $dashStep->y),
        ));
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
