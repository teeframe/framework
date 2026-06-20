<?php

use TeeFrame\Game\Entities\CharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;

$mapPath = __DIR__ . '/../../../teeworlds/data/maps/dm1.map';
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
    $tee->inputFire      = false;
    $tee->inputHook      = false;

    $character = new CharacterEntity(clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    for ($tick = 0; $tick < 100; $tick++) {
        $character->tickPhysics(0, 0, 0, false, false, $collision);
        $character->move($collision);
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
    $tee->inputFire      = false;
    $tee->inputHook      = false;

    $character = new CharacterEntity(clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    for ($tick = 0; $tick < 100; $tick++) {
        $character->tickPhysics($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision);
        $character->move($collision);
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
    $tee->inputFire      = false;
    $tee->inputHook      = true;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);

    for ($tick = 0; $tick < 50; $tick++) {
        $character->tickPhysics($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision);
        $character->move($collision);
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
    $tee->inputFire      = true;
    $tee->inputHook      = false;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);

    for ($tick = 0; $tick < 50; $tick++) {
        $character->tickPhysics($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision);
        $character->move($collision);
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
    $tee->inputFire      = true;
    $tee->inputHook      = true;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);

    // Run a few ticks to exercise hook state machine
    for ($tick = 0; $tick < 10; $tick++) {
        $character->tickPhysics($tee->inputDirection, $tee->inputTargetX, $tee->inputTargetY, $tee->inputJump, $tee->inputHook, $collision);
        $character->move($collision);
    }

    // Snap should produce valid items
    $snaps = $character->doSnap($tee);
    expect($snaps)->toHaveCount(1);

    $ints = $snaps[0]->getInts();
    expect($ints)->toHaveCount(22); // ObjCharacterItem has 22 fields

    // All values should be finite integers
    foreach ($ints as $val) {
        expect(is_finite($val))->toBeTrue();
        expect($val)->toBeInt();
    }
});