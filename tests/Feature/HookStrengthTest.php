<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

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
            $clock->advanceTick();
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
    $target->doTickDeferred();
    $hooker->doTickDeferred();
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

test('non-positive hook length releases an attached player hook and can be re-enabled', function (float $length) {
    [$world, $clock, $characters] = createHookStrengthWorld();
    $tuning                       = $world->getTuneController();
    $tuning->gravity              = 0;
    $hooker                       = $characters['hooker'];
    $target                       = $characters['target'];
    $targetTee                    = $target->tee;
    assert($targetTee instanceof PlayerTee);
    $hooker->onPredictedInput(input(['targetX' => 100, 'targetY' => 0, 'hook' => true]));

    for ($i = 0; $i < 3; $i++) {
        $world->doTick();
        $clock->advanceTick();
    }

    expect($hooker->hookedPlayer)->toBe($targetTee->teeIndex);
    $velocity = clone $target->vel;
    $tuning->set('hook_length', $length);
    $world->doTick();

    expect($hooker->hookState)->toBe(GameConstants::HOOK_IDLE);
    expect($hooker->hookedPlayer)->toBe(-1);
    expect($hooker->hookPos)->toEqual($hooker->getPosition());
    expect($target->vel->x)->toBe(round($velocity->x * 0.95 * 256.0) / 256.0);
    expect($hooker->hookDragVelocity)->toEqual(new Vector2(0, 0));
    expect($target->hookDragVelocity)->toEqual(new Vector2(0, 0));

    $clock->advanceTick();
    $world->doTick();
    expect($hooker->hookState)->toBe(GameConstants::HOOK_IDLE);
    expect($hooker->triggeredEvents & GameConstants::COREEVENT_HOOK_LAUNCH)->toBe(0);

    $tuning->reset('hook_length');

    for ($i = 0; $i < 3; $i++) {
        $clock->advanceTick();
        $world->doTick();
    }

    expect($hooker->hookState)->toBe(GameConstants::HOOK_GRABBED);
    expect($hooker->hookedPlayer)->toBe($targetTee->teeIndex);
})->with([0.0, -1.0]);

test('non-positive hook length prevents launches and releases flying or ground hooks', function (int $state, float $length) {
    [$world, , $characters] = createHookStrengthWorld();
    $tuning                 = $world->getTuneController();
    $tuning->gravity        = 0;
    $tuning->set('hook_length', $length);
    $hooker            = $characters['hooker'];
    $hooker->hookState = $state;
    $hooker->hookPos   = new Vector2(128, 64);
    $hooker->hookDir   = new Vector2(0, -1);
    $hooker->onPredictedInput(input(['targetX' => 0, 'targetY' => -100, 'hook' => true]));

    $world->doTick();

    expect($hooker->hookState)->toBe(GameConstants::HOOK_IDLE);
    expect($hooker->hookedPlayer)->toBe(-1);
    expect($hooker->hookPos)->toEqual($hooker->getPosition());
    expect($hooker->triggeredEvents & GameConstants::COREEVENT_HOOK_LAUNCH)->toBe(0);
    expect($hooker->vel)->toEqual(new Vector2(0, 0));
})->with([GameConstants::HOOK_IDLE, GameConstants::HOOK_FLYING, GameConstants::HOOK_GRABBED])->with([0.0, -1.0]);

test('released hooks do not leave drag accumulated for the next tick', function () {
    [$world, $clock, $characters] = createHookStrengthWorld();
    attachStrengthTestHook($characters['hooker'], $characters['target']);
    $world->doTick();
    $velocity = clone $characters['target']->vel;
    $characters['hooker']->onPredictedInput(input());
    $clock->advanceTick();

    $world->doTick();

    expect($characters['target']->vel->x)->toBe(round($velocity->x * 0.95 * 256.0) / 256.0);
    expect($characters['target']->vel->y)->toBe(round(($velocity->y + 0.5) * 256.0) / 256.0);
    expect($characters['target']->hookDragVelocity)->toEqual(new Vector2(0, 0));
});

test('departing tees release all attached hooks before their id can be reused even while paused', function (bool $paused) {
    [$world, $clock, $characters]        = createHookStrengthWorld(['hooker', 'target', 'secondHooker']);
    $world->getTuneController()->gravity = 0;
    $targetTee                           = $characters['target']->tee;
    assert($targetTee instanceof PlayerTee);
    $hookers = [$characters['hooker'], $characters['secondHooker']];
    foreach ($hookers as $i => $hooker) {
        $hooker->onPredictedInput(input(['targetX' => $i === 0 ? 100 : -100, 'targetY' => 0, 'hook' => true]));
    }
    for ($i = 0; $i < 3; $i++) {
        $world->doTick();
        $clock->advanceTick();
    }
    foreach ($hookers as $hooker) {
        expect($hooker->hookState)->toBe(GameConstants::HOOK_GRABBED);
        expect($hooker->hookedPlayer)->toBe($targetTee->teeIndex);
    }

    $world->setPaused($paused);
    $world->removeTee($targetTee);

    foreach ($hookers as $hooker) {
        expect($hooker->hookState)->toBe(GameConstants::HOOK_RETRACTED);
        expect($hooker->hookedPlayer)->toBe(-1);
        expect($hooker->hookPos)->toEqual($hooker->getPosition());
    }
    $replacement = new PlayerTee;
    $world->addTee($replacement);
    expect($replacement->teeIndex)->toBe($targetTee->teeIndex);
    $position  = new Vector2(320, 256);
    $character = new PvpCharacterEntity($world, $position);
    $character->spawn($position, $replacement);
    $world->addEntity($character);

    if ($paused) {
        for ($i = 0; $i < 100; $i++) {
            $world->doTick();
            $clock->advanceTick();
        }
    }
    foreach ($hookers as $hooker) {
        $snapshot = $hooker->doSnap($replacement)[0];
        expect($snapshot)->toBeInstanceOf(ObjCharacterItem::class);
        assert($snapshot instanceof ObjCharacterItem);
        expect($snapshot->hookedPlayer)->toBe(-1);
        expect($snapshot->hookState)->toBe(GameConstants::HOOK_RETRACTED);
    }
    $world->setPaused(false);
    for ($i = 0; $i < 3; $i++) {
        $world->doTick();
        $clock->advanceTick();
    }

    expect($character->getPosition())->toEqual(new Vector2(320, 256));
    expect($character->vel)->toEqual(new Vector2(0, 0));
    foreach ($hookers as $hooker) {
        expect($hooker->hookedPlayer)->toBe(-1);
        expect($hooker->hookState)->toBe(GameConstants::HOOK_RETRACTED);
    }
})->with([false, true]);

test('a departing tee does not release hooks flying attached to the ground or attached to another tee', function (int $state, int $targetIndex) {
    [$world, , $characters] = createHookStrengthWorld(['hooker', 'target', 'secondHooker']);
    $hooker                 = $characters['hooker'];
    $hooker->hookState      = $state;
    $hooker->hookedPlayer   = $targetIndex;
    $position               = new Vector2(320, 64);
    $hooker->hookPos        = clone $position;
    $targetTee              = $characters['target']->tee;
    assert($targetTee instanceof PlayerTee);

    $world->removeTee($targetTee);

    expect($hooker->hookState)->toBe($state);
    expect($hooker->hookedPlayer)->toBe($targetIndex);
    expect($hooker->hookPos)->toEqual($position);
})->with([
    'flying'    => [GameConstants::HOOK_FLYING, -1],
    'ground'    => [GameConstants::HOOK_GRABBED, -1],
    'other tee' => [GameConstants::HOOK_GRABBED, 2],
]);

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
