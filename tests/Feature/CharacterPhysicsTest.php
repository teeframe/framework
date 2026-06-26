<?php

use TeeFrame\Game\Entities\Character\CharacterCore;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('character spawns and survives physics ticks at spawn position', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);
    $initialY = $spawnPos->y;

    $tee = new PlayerTee;
    $tee->inputDirection = 0;
    $tee->inputTargetX   = 0;
    $tee->inputTargetY   = 0;
    $tee->inputJump      = false;
    $tee->inputFire      = 0;
    $tee->inputHook      = false;

    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->tuneController();

    for ($tick = 0; $tick < 100; $tick++) {
        $character->core->tick(0, 0, 0, false, false, $collision, $tune, []);
        $character->core->move($collision, $tune);
        $character->position->x = $character->core->position->x;
        $character->position->y = $character->core->position->y;
    }

    expect($character->alive)->toBeTrue();
    expect($character->position->y)->toBeGreaterThan($initialY);
});

test('character with walk input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);
    $initialX = $spawnPos->x;

    $tee = new PlayerTee;
    $tee->inputDirection = 1;  // walking right
    $tee->inputTargetX   = 100;
    $tee->inputTargetY   = 0;
    $tee->inputJump      = false;
    $tee->inputFire      = 0;
    $tee->inputHook      = false;

    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->tuneController();

    for ($tick = 0; $tick < 100; $tick++) {
        $character->core->tick($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision, $tune, []);
        $character->core->move($collision, $tune);
        $character->position->x = $character->core->position->x;
        $character->position->y = $character->core->position->y;
    }

    expect($character->alive)->toBeTrue();
    expect($character->position->x)->toBeGreaterThan($initialX);
});

test('character with hook input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;
    $tee->inputDirection = 0;
    $tee->inputTargetX   = 100;
    $tee->inputTargetY   = 0;
    $tee->inputJump      = false;
    $tee->inputFire      = 0;
    $tee->inputHook      = true;

    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    $tune = $world->tuneController();

    for ($tick = 0; $tick < 50; $tick++) {
        $character->core->tick($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision, $tune, []);
        $character->core->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
});

test('character with firing input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;
    $tee->inputDirection = 0;
    $tee->inputTargetX   = 100;
    $tee->inputTargetY   = -50;  // aiming up-right
    $tee->inputJump      = false;
    $tee->inputFire      = 1;
    $tee->inputHook      = false;

    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    $tune = $world->tuneController();

    for ($tick = 0; $tick < 50; $tick++) {
        $character->core->tick($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision, $tune, []);
        $character->core->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
});

test('character snap output is valid', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;
    $tee->inputDirection = 0;
    $tee->inputTargetX   = 100;
    $tee->inputTargetY   = -100;
    $tee->inputJump      = true;
    $tee->inputFire      = 1;
    $tee->inputHook      = true;

    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    // Run a few ticks to exercise hook state machine
    $tune = $world->tuneController();

    for ($tick = 0; $tick < 10; $tick++) {
        $character->core->tick($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision, $tune, []);
        $character->core->move($collision, $tune);
    }

    // Snap should produce valid items
    $snaps = $character->doSnap($tee);
    expect($snaps)->toHaveCount(1);

    $item = $snaps[0];
    expect($item->getItemId())->toBe(9); // NETOBJTYPE_CHARACTER

    $ints = $item->getInts();
    expect(count($ints))->toBe(22);

    // All ints should be finite
    foreach ($ints as $val) {
        expect($val)->toBeInt();
    }
});

test('hook stops at wall collision point not past it', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;
    $world = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->tuneController();

    // Set hook state to flying toward the right
    $character->core->hookState = 4; // HOOK_FLYING
    $character->core->hookDir = new Vector2(1, 0);
    $character->core->hookPos = clone $character->position;

    // Compute full extension point (where hook would go without collision)
    $fullExtension = new Vector2(
        $character->core->hookPos->x + $character->core->hookDir->x * 80.0,
        $character->core->hookPos->y + $character->core->hookDir->y * 80.0,
    );

    // Check if there's a wall between hookPos and fullExtension
    [$hit, $expectedColPos] = $collision->intersectLine($character->core->hookPos, $fullExtension);

    if (! $hit) {
        // No wall in range, can't test collision — but hook should still extend
        return;
    }

    // Run the hook state machine
    $ref = new ReflectionClass(CharacterCore::class);
    $method = $ref->getMethod('tickHookStateMachine');
    $method->setAccessible(true);
    $method->invoke($character->core, $collision, $tune, []);

    // hookPos must be at the collision point, not past it (at the full extension)
    expect($character->core->hookPos->x)->toBe($expectedColPos->x);
    expect($character->core->hookPos->y)->toBe($expectedColPos->y);
});

test('players push each other apart when overlapping', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Create world so tickPhysics can access other entities
    $world = createWorld($map);

    // Player 1 at spawn
    $tee1 = new PlayerTee;
    $char1 = new PvpCharacterEntity($world, clone $spawnPos);
    $char1->spawn(clone $spawnPos, $tee1);
    $world->addEntity($char1);

    // Player 2 at same position (overlapping)
    $tee2 = new PlayerTee;
    $char2 = new PvpCharacterEntity($world, clone $spawnPos);
    $char2->spawn(clone $spawnPos, $tee2);
    $world->addEntity($char2);

    // Run one physics tick for both — they should push apart
    $tune = $world->tuneController();

    $char1->core->tick(0, 0, 0, false, false, $collision, $tune, [$tee2->teeIndex => $char2->core]);
    $char1->core->move($collision, $tune);
    $char1->position->x = $char1->core->position->x;
    $char1->position->y = $char1->core->position->y;
    $char2->core->tick(0, 0, 0, false, false, $collision, $tune, [$tee1->teeIndex => $char1->core]);
    $char2->core->move($collision, $tune);
    $char2->position->x = $char2->core->position->x;
    $char2->position->y = $char2->core->position->y;

    // After collision resolution, velocities should push them apart
    // (collision modifies vel, move applies position change)
    $dist = $char1->position->distance($char2->position);
    expect($dist)->toBeGreaterThan(0);
});