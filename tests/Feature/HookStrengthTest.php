<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;

/**
 * @param  list<string>  $clientOrder
 * @return array{TestWorld, TickHandler, array<string, PvpCharacterEntity>}
 */
function createHookStrengthWorld(array $clientOrder = ['hooker', 'target'], bool $reverseEntities = false, bool $grounded = false): array
{
    $clock = new TickHandler(100);
    $map   = new class($grounded) extends Map
    {
        public function __construct(bool $grounded)
        {
            $air             = pack('C4', GameLayer::TILE_AIR, 0, 0, 0);
            $floor           = pack('C4', $grounded ? GameLayer::TILE_SOLID : GameLayer::TILE_AIR, 0, 0, 0);
            $this->gameLayer = new GameLayer(32, 16, str_repeat($air, 32 * 10).str_repeat($floor, 32 * 6));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $world      = new TestWorld($clock, $map);
    $positions  = ['hooker' => 128, 'target' => 320, 'secondHooker' => 512];
    $characters = [];
    foreach ($clientOrder as $role) {
        $tee = new PlayerTee;
        $world->addTee($tee);
        $position  = new Vector2($positions[$role], $grounded ? 306 : 128);
        $character = new PvpCharacterEntity($world, $position);
        $character->spawn($position, $tee);
        $characters[$role] = $character;
    }
    foreach ($reverseEntities ? array_reverse($characters) : $characters as $character) {
        $world->addEntity($character);
    }

    return [$world, $clock, $characters];
}

function attachStrengthTestHook(PvpCharacterEntity $hooker, PvpCharacterEntity $target): void
{
    $targetTee = $target->tee;
    assert($targetTee !== null);
    $direction            = $target->getPosition()->diff($hooker->getPosition())->normalize();
    $hooker->hookState    = GameConstants::HOOK_GRABBED;
    $hooker->hookedPlayer = $targetTee->teeIndex;
    $hooker->hookPos      = clone $target->getPosition();
    $hooker->onPredictedInput(input([
        'hook'    => true,
        'targetX' => (int) ($direction->x * 100),
        'targetY' => (int) ($direction->y * 100),
    ]));
}

/**
 * @param  array<string, PvpCharacterEntity>  $characters
 * @return array<string, list<float|int>>
 */
function hookStrengthState(array $characters): array
{
    $state = [];
    foreach ($characters as $role => $character) {
        $state[$role] = [
            $character->getPosition()->x,
            $character->getPosition()->y,
            $character->vel->x,
            $character->vel->y,
            $character->hookState,
            $character->hookTick,
        ];
    }
    ksort($state);

    return $state;
}

test('tee hook strength is independent of client ids and entity tick order', function (bool $grounded, int $direction, bool $jump) {
    $worlds = [];
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$reverseIds, $reverseEntities]) {
        [$world, $clock, $characters] = createHookStrengthWorld(
            $reverseIds ? ['target', 'hooker'] : ['hooker', 'target'],
            $reverseEntities,
            $grounded,
        );
        $characters['target']->vel = new Vector2(4, 0);
        $characters['target']->onPredictedInput(input(['direction' => $direction, 'jump' => $jump]));
        attachStrengthTestHook($characters['hooker'], $characters['target']);
        $worlds[] = [$world, $clock, $characters];
    }

    for ($tick = 0; $tick < 6; $tick++) {
        $expected = null;
        foreach ($worlds as [$world, $clock, $characters]) {
            $world->doTick();
            $state = hookStrengthState($characters);
            $expected ??= $state;
            expect($state)->toBe($expected);
            $clock->next();
        }
    }
})->with([false, true])->with([-1, 0, 1])->with([false, true]);

test('hook forces do not modify either tees baseline velocity during core ticking', function () {
    [$world, , $characters]                    = createHookStrengthWorld();
    $hooker                                    = $characters['hooker'];
    $target                                    = $characters['target'];
    $target->vel                               = new Vector2(4, 0);
    $world->getTuneController()->hookDragAccel = 400;
    attachStrengthTestHook($hooker, $target);

    $hooker->doTick();

    expect($target->vel)->toEqual(new Vector2(4, 0));
    expect($hooker->vel->x)->toBe(0.0);
    $target->doTick();
    expect($target->vel->x)->toBe(4.0 * 0.95);
    $acceleration = 4.0 * (192.0 / 380.0);
    $target->tickDeferred();
    $hooker->tickDeferred();
    expect($target->vel->x)->toBe(round((4.0 * 0.95 - $acceleration * 1.5) * 256.0) / 256.0);
    expect($hooker->vel->x)->toBe(round($acceleration * 0.25 * 256.0) / 256.0);
    expect($target->hookDragVelocity)->toEqual(new Vector2(0, 0));
    expect($hooker->hookDragVelocity)->toEqual(new Vector2(0, 0));
});

test('opposing hooks accumulate before clamping rather than clamping each hook separately', function () {
    $orders = [
        ['hooker', 'target', 'secondHooker'],
        ['hooker', 'secondHooker', 'target'],
        ['target', 'hooker', 'secondHooker'],
        ['target', 'secondHooker', 'hooker'],
        ['secondHooker', 'hooker', 'target'],
        ['secondHooker', 'target', 'hooker'],
    ];
    $expected = null;
    foreach ($orders as $order) {
        [$world, , $characters]    = createHookStrengthWorld($order);
        $characters['target']->vel = new Vector2(14, 0);
        attachStrengthTestHook($characters['hooker'], $characters['target']);
        attachStrengthTestHook($characters['secondHooker'], $characters['target']);

        $world->doTick();

        expect($characters['target']->vel->x)->toBe(round(14.0 * 0.95 * 256.0) / 256.0);
        $state = hookStrengthState($characters);
        $expected ??= $state;
        expect($state)->toBe($expected);
    }
});

test('deferred hook drag still respects the configured drag speed', function (int $dragSpeed, bool $vertical) {
    [$world, , $characters]                    = createHookStrengthWorld();
    $world->getTuneController()->hookDragSpeed = $dragSpeed;
    $world->getTuneController()->hookDragAccel = 10000;
    if ($vertical) {
        $characters['hooker']->setPosition(new Vector2(320, 320));
    }
    attachStrengthTestHook($characters['hooker'], $characters['target']);

    $world->doTick();

    expect($vertical ? $characters['target']->vel->y : $characters['target']->vel->x)->toBe(($vertical ? $dragSpeed : -$dragSpeed) / 100.0);
    expect($characters['target']->hookDragVelocity)->toEqual(new Vector2(0, 0));
})->with([500, 1500])->with([false, true]);

test('released hooks do not leave drag accumulated for the next tick', function () {
    [$world, $clock, $characters] = createHookStrengthWorld();
    attachStrengthTestHook($characters['hooker'], $characters['target']);
    $world->doTick();
    $velocity = clone $characters['target']->vel;
    $characters['hooker']->onPredictedInput(input());
    $clock->next();

    $world->doTick();

    expect($characters['target']->vel->x)->toBe(round($velocity->x * 0.95 * 256.0) / 256.0);
    expect($characters['target']->vel->y)->toBe(round(($velocity->y + 0.5) * 256.0) / 256.0);
    expect($characters['target']->hookDragVelocity)->toEqual(new Vector2(0, 0));
});

test('ninja discards hook drag during the dash and its final movement tick', function (int $moveTime) {
    [$world, , $characters] = createHookStrengthWorld();
    $target                 = $characters['target'];
    $target->giveNinja();
    $target->ninjaActivationDir   = new Vector2(1, 0);
    $target->ninjaCurrentMoveTime = $moveTime;
    $target->ninjaOldVelAmount    = 0.0;
    attachStrengthTestHook($characters['hooker'], $target);

    $world->doTick();

    expect($target->ninjaCurrentMoveTime)->toBe($moveTime - 1);
    expect($target->vel)->toEqual(new Vector2(0, 0));
    expect($target->getPosition()->x)->toBe($moveTime > 1 ? 370.0 : 320.0);
    expect($target->hookDragVelocity)->toEqual(new Vector2(0, 0));
})->with([1, 3]);

test('idle ninja can be dragged normally between dashes', function () {
    [$world, , $characters] = createHookStrengthWorld();
    $target                 = $characters['target'];
    $target->giveNinja();
    attachStrengthTestHook($characters['hooker'], $target);

    $world->doTick();

    expect($target->ninjaCurrentMoveTime)->toBe(-1);
    expect($target->vel->x)->toBeLessThan(0.0);
    expect($target->hookDragVelocity)->toEqual(new Vector2(0, 0));
});

test('spawning clears pending hook drag on a reused character', function () {
    [$world, , $characters]   = createHookStrengthWorld();
    $target                   = $characters['target'];
    $target->hookDragVelocity = new Vector2(3, 4);

    $target->spawn(new Vector2(320, 128), $target->tee);

    expect($target->hookDragVelocity)->toEqual(new Vector2(0, 0));
});
