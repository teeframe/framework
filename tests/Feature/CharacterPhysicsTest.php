<?php

use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

test('character spawns and survives physics ticks at spawn position', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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

    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->getTuneController();

    for ($tick = 0; $tick < 100; $tick++) {
        $character->tick(0, 0, 0, false, false, $collision, $tune, []);
        $character->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
    expect($character->getPosition()->y)->toBeGreaterThan($initialY);
});

test('character with walk input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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

    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->getTuneController();

    // walking right, aiming right
    for ($tick = 0; $tick < 100; $tick++) {
        $character->tick(1, 100, 0, false, false, $collision, $tune, []);
        $character->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
    expect($character->getPosition()->x)->toBeGreaterThan($initialX);
});

test('character with hook input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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

    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    $tune = $world->getTuneController();

    // hook input, aiming right
    for ($tick = 0; $tick < 50; $tick++) {
        $character->tick(0, 100, 0, false, true, $collision, $tune, []);
        $character->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
});

test('character with firing input survives physics ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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

    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    $tune = $world->getTuneController();

    // aiming up-right, firing
    for ($tick = 0; $tick < 50; $tick++) {
        $character->tick(0, 100, -50, false, false, $collision, $tune, []);
        $character->move($collision, $tune);
    }

    expect($character->alive)->toBeTrue();
});

test('character snap output is valid', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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

    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    // Run a few ticks to exercise hook state machine
    $tune = $world->getTuneController();

    // aiming up-right, jumping, firing, hooking
    for ($tick = 0; $tick < 10; $tick++) {
        $character->tick(0, 100, -100, true, true, $collision, $tune, []);
        $character->move($collision, $tune);
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

    $map       = new Map($mapPath);
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

    $tee       = new PlayerTee;
    $world     = createWorld($map);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $tune = $world->getTuneController();

    // Set hook state to flying toward the right
    $character->hookState = GameConstants::HOOK_FLYING;
    $character->hookDir   = new Vector2(1, 0);
    $character->hookPos   = clone $character->getPosition();

    // Compute full extension point (where hook would go without collision)
    $fullExtension = new Vector2(
        $character->hookPos->x + $character->hookDir->x * 80.0,
        $character->hookPos->y + $character->hookDir->y * 80.0,
    );

    // Check if there's a wall between hookPos and fullExtension
    [$hit, $expectedColPos] = $collision->intersectLine($character->hookPos, $fullExtension);

    if (! $hit) {
        // No wall in range, can't test collision — but hook should still extend
        return;
    }

    // Run the hook state machine
    $ref    = new ReflectionClass(PvpCharacterEntity::class);
    $method = $ref->getMethod('tickHookStateMachine');
    $method->invoke($character, $collision, $tune, []);

    // hookPos must be at the collision point, not past it (at the full extension)
    expect($character->hookPos->x)->toBe($expectedColPos->x);
    expect($character->hookPos->y)->toBe($expectedColPos->y);
});

test('players push each other apart when overlapping', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
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
    $tee1  = new PlayerTee;
    $char1 = new PvpCharacterEntity($world, clone $spawnPos);
    $char1->spawn(clone $spawnPos, $tee1);
    $world->addEntity($char1);

    // Player 2 at same position (overlapping)
    $tee2  = new PlayerTee;
    $char2 = new PvpCharacterEntity($world, clone $spawnPos);
    $char2->spawn(clone $spawnPos, $tee2);
    $world->addEntity($char2);

    // Run one physics tick for both — they should push apart
    $tune = $world->getTuneController();

    $char1->tick(0, 0, 0, false, false, $collision, $tune, [$tee2->teeIndex => $char2]);
    $char1->move($collision, $tune);
    $char2->tick(0, 0, 0, false, false, $collision, $tune, [$tee1->teeIndex => $char1]);
    $char2->move($collision, $tune);

    // After collision resolution, velocities should push them apart
    // (collision modifies vel, move applies position change)
    $dist = $char1->getPosition()->distance($char2->getPosition());
    expect($dist)->toBeGreaterThan(0);
});
