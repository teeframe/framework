<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

/** @return array{TestWorld, TickHandler} */
function createCharacterMovementWorld(): array
{
    $clock = new TickHandler(100);
    $map   = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(64, 64, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 64 * 64));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };

    return [new TestWorld($clock, $map), $clock];
}

function addMovementCharacter(TestWorld $world, float $x, float $y = 128): PvpCharacterEntity
{
    $tee = new PlayerTee;
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, new Vector2($x, $y));
    $character->spawn(new Vector2($x, $y), $tee);
    $world->addEntity($character);

    return $character;
}

test('velocity ramps stay finite at numerical limits and preserve ordinary factors', function (float $value, float $start, float $range, float $curvature, float $expected) {
    $ramp = PvpCharacterEntity::velocityRamp($value, $start, $range, $curvature);

    expect(is_finite($ramp))->toBeTrue();
    expect($ramp)->toBe($expected);
})->with([
    'below threshold'     => [100.0, 550.0, 2000.0, 1.4, 1.0],
    'at threshold'        => [550.0, 550.0, 2000.0, 1.4, 1.0],
    'ordinary ramp'       => [1000.0, 550.0, 2000.0, 1.4, 1.0 / pow(1.4, 450.0 / 2000.0)],
    'power overflow'      => [1000.0, 550.0, 0.01, 1.4, 0.0],
    'power underflow'     => [1000.0, 550.0, 0.01, 0.01, PHP_FLOAT_MAX],
    'reciprocal overflow' => [16.0, 0.0, 0.01, 0.63, PHP_FLOAT_MAX],
    'zero range'          => [1000.0, 550.0, 0.0, 1.4, 1.0],
    'zero curvature'      => [1000.0, 550.0, 2000.0, 0.0, 1.0],
]);

test('extreme velocity ramps keep movement bounded without corrupting stored momentum', function (float $range, float $curvature, float $velocityX, float $velocityY, float $movementX) {
    [$world]   = createCharacterMovementWorld();
    $character = addMovementCharacter($world, 128);
    $tuning    = $world->getTuneController();
    $tuning->set('velramp_range', $range);
    $tuning->set('velramp_curvature', $curvature);
    $character->vel = new Vector2($velocityX, $velocityY);
    $collision      = $world->getMap()->getCollision();
    assert($collision !== null);

    $character->move($collision, $tuning);

    expect(is_finite($character->getPosition()->x))->toBeTrue();
    expect(is_finite($character->getPosition()->y))->toBeTrue();
    expect($character->getPosition()->x)->toEqualWithDelta(128 + $movementX, 0.000001);
    expect($character->getPosition()->y)->toEqualWithDelta(128 + $velocityY, 0.000001);
    expect($character->vel)->toEqual(new Vector2($velocityX, $velocityY));
})->with([
    'ramp becomes zero'                         => [0.01, 1.4, 20.0, 0.0, 0.0],
    'large finite ramp denominator'             => [0.64, 2.72, 20.0, 0.0, 0.0],
    'ramp amplification overflows'              => [0.01, 0.01, 20.0, 0.0, 6000.0],
    'finite excessive amplification'            => [1.0, 0.5, 20.0, 0.0, 6000.0],
    'amplification while moving left'           => [0.01, 0.01, -20.0, 0.0, -6000.0],
    'amplification with no horizontal velocity' => [0.01, 0.01, 0.0, 20.0, 0.0],
    'ordinary ramp'                             => [2000.0, 1.4, 20.0, 0.0, 20.0 / pow(1.4, 450.0 / 2000.0)],
]);

test('character movement checks tees along the entire map-clipped path', function (float $targetX, float $velocity, float $expectedX) {
    [$world]                                  = createCharacterMovementWorld();
    $mover                                    = addMovementCharacter($world, 128);
    $target                                   = addMovementCharacter($world, $targetX);
    $world->getTuneController()->velrampStart = 1_000_000;
    $mover->vel                               = new Vector2($velocity, 0);
    $collision                                = $world->getMap()->getCollision();
    assert($collision !== null);

    $mover->move($collision, $world->getTuneController(), [$target]);

    expect($mover->getPosition()->x)->toEqualWithDelta($expectedX, 0.00000001);
    expect($mover->getPosition()->distance($target->getPosition()))->toBeGreaterThanOrEqual(28.0);
    expect($mover->vel->x)->toBe($velocity);
})->with([
    'ordinary movement'      => [168.0, 40.0, 140.0],
    'crossing the whole tee' => [220.0, 200.0, 192.0],
    'moving left'            => [88.0, -40.0, 116.0],
]);

test('swept tee collision does not replace an earlier map collision', function (bool $ramped) {
    [$world] = createCharacterMovementWorld();
    $mover   = addMovementCharacter($world, 128);
    $target  = addMovementCharacter($world, 200);
    $tiles   = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 16; $x++) {
            $tiles .= pack('C4', $x === 5 ? GameLayer::TILE_SOLID : GameLayer::TILE_AIR, 0, 0, 0);
        }
    }
    $collision = new Collision;
    $collision->init(new GameLayer(16, 8, $tiles));
    $world->getTuneController()->velrampStart = $ramped ? 0 : 1_000_000;
    $mover->vel                               = new Vector2(100, 0);

    $mover->move($collision, $world->getTuneController(), [$target]);

    expect($mover->getPosition()->x)->toBeGreaterThanOrEqual(128.0);
    expect($mover->getPosition()->x)->toBeLessThanOrEqual(146.0);
    expect($mover->vel->x)->toBe(0.0);
})->with([false, true]);

test('overlapping tees can move apart but cannot move deeper into each other', function (float $velocity, float $expectedX) {
    [$world]    = createCharacterMovementWorld();
    $mover      = addMovementCharacter($world, 128);
    $target     = addMovementCharacter($world, 148);
    $mover->vel = new Vector2($velocity, 0);
    $collision  = $world->getMap()->getCollision();
    assert($collision !== null);

    $mover->move($collision, $world->getTuneController(), [$target]);

    expect($mover->getPosition()->x)->toEqualWithDelta($expectedX, 0.00000001);
})->with(['separating' => [-10.0, 118.0], 'approaching' => [10.0, 128.0], 'stationary' => [0.0, 128.0]]);

test('a tangent at the strict tee collision boundary does not block movement', function () {
    [$world]                                  = createCharacterMovementWorld();
    $mover                                    = addMovementCharacter($world, 128);
    $target                                   = addMovementCharacter($world, 148, 156);
    $mover->vel                               = new Vector2(20, 0);
    $world->getTuneController()->velrampStart = 1_000_000;
    $collision                                = $world->getMap()->getCollision();
    assert($collision !== null);

    $mover->move($collision, $world->getTuneController(), [$target]);

    expect($mover->getPosition()->x)->toEqualWithDelta(148.0, 0.00000001);
});

test('tee movement respects collision tuning and ignores dead or destroyed characters', function (string $state) {
    [$world]                                  = createCharacterMovementWorld();
    $mover                                    = addMovementCharacter($world, 128);
    $target                                   = addMovementCharacter($world, 168);
    $world->getTuneController()->velrampStart = 1_000_000;
    if ($state === 'disabled') {
        $world->getTuneController()->playerCollision = 0;
    } elseif ($state === 'dead') {
        $target->alive = false;
    } else {
        $target->markForDestruction();
    }
    $mover->vel = new Vector2(80, 0);
    $collision  = $world->getMap()->getCollision();
    assert($collision !== null);

    $mover->move($collision, $world->getTuneController(), [$mover, $target]);

    expect($mover->getPosition()->x)->toEqualWithDelta(208.0, 0.00000001);
})->with(['disabled', 'dead', 'destroyed']);

test('the running world uses swept collisions without consulting another worlds characters', function (bool $sameWorld) {
    [$world] = createCharacterMovementWorld();
    [$other] = createCharacterMovementWorld();
    $mover   = addMovementCharacter($world, 128);
    addMovementCharacter($sameWorld ? $world : $other, 168);
    $world->getTuneController()->gravity      = 0;
    $world->getTuneController()->airFriction  = 100;
    $world->getTuneController()->velrampStart = 1_000_000;
    $mover->vel                               = new Vector2(80, 0);

    $world->doTick();

    expect($mover->getPosition()->x)->toBe($sameWorld ? 140.0 : 208.0);
})->with([false, true]);

test('character quantization rounds core fields exactly to their wire precision', function () {
    [$world]            = createCharacterMovementWorld();
    $character          = addMovementCharacter($world, 128.5, -128.5);
    $character->vel     = new Vector2(1 + 0.5 / 256, -1 - 0.5 / 256);
    $character->hookPos = new Vector2(100.5, -100.5);
    $character->hookDir = new Vector2(0.3, -0.7);

    $character->quantize();

    expect($character->getPosition())->toEqual(new Vector2(129, -129));
    expect($character->vel)->toEqual(new Vector2(257 / 256, -257 / 256));
    expect($character->hookPos)->toEqual(new Vector2(101, -101));
    expect($character->hookDir)->toEqual(new Vector2(77 / 256, -179 / 256));
    $before = [$character->getPosition()->x, $character->getPosition()->y, $character->vel->x, $character->vel->y];
    $character->quantize();
    expect([$character->getPosition()->x, $character->getPosition()->y, $character->vel->x, $character->vel->y])->toBe($before);
});

test('every completed world tick retains the same core state that it sends to clients', function () {
    [$world, $clock] = createCharacterMovementWorld();
    $character       = addMovementCharacter($world, 128);
    $character->vel  = new Vector2(0.3, 0.7);
    $character->onPredictedInput(input(['targetX' => 3, 'targetY' => -4, 'hook' => true]));
    for ($i = 0; $i < 60; $i++) {
        $world->doTick();
        $snap = $character->doSnap(new PlayerTee)[0];
        assert($snap instanceof ObjCharacterItem);
        expect([$character->getPosition()->x, $character->getPosition()->y])->toBe([(float) $snap->x, (float) $snap->y]);
        expect([$character->vel->x, $character->vel->y])->toBe([$snap->velX / 256.0, $snap->velY / 256.0]);
        expect([$character->hookPos->x, $character->hookPos->y])->toBe([(float) $snap->hookX, (float) $snap->hookY]);
        expect([$character->hookDir->x, $character->hookDir->y])->toBe([$snap->hookDx / 256.0, $snap->hookDy / 256.0]);
        $clock->advanceTick();
    }
});

test('physics for all tees runs before any tee is moved or rounded', function () {
    [$world] = createCharacterMovementWorld();
    $first   = addMovementCharacter($world, 128);
    $second  = new class($world, new Vector2(512, 128), $first) extends PvpCharacterEntity
    {
        public ?Vector2 $observedPosition = null;

        public function __construct(TestWorld $world, Vector2 $position, protected PvpCharacterEntity $first)
        {
            parent::__construct($world, $position);
        }

        public function doTick(): void
        {
            $this->observedPosition = clone $this->first->getPosition();
            $this->first->vel->x += 0.001;
            parent::doTick();
        }
    };
    $tee = new PlayerTee;
    $world->addTee($tee);
    $second->spawn(new Vector2(512, 128), $tee);
    $world->addEntity($second);
    $first->vel = new Vector2(0.3, 0.7);

    $world->doTick();

    expect($second->observedPosition)->toEqual(new Vector2(128, 128));
    expect($first->getPosition()->x)->toBe(round($first->getPosition()->x));
    expect($first->vel->x * 256)->toBe(round($first->vel->x * 256));
});
