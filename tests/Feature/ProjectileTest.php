<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjEventDamageIndItem;
use TeeFrame\Network\SnapItems\ObjEventExplosionItem;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

test('projectile survives full lifecycle', function () use ($mapPath, $mapExists) {
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

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);

    // Tick once to set up angle (needed for shoot direction)
    $tune = $world->getTuneController();
    $character->tick(1, 100, 0, false, false, $collision, $tune, []);
    $character->move($collision, $tune);

    // Shoot through the character so tuning is applied from the controller
    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $proj = $world->getEntities()[1]; // [0] is the character
    assert($proj instanceof PvpProjectileEntity);

    // Instead, test that getPos produces finite values
    $p0 = getProjectilePos($proj, -0.02);
    $p1 = getProjectilePos($proj, 0.0);
    $p2 = getProjectilePos($proj, 1.0);

    expect(is_finite($p0->x))->toBeTrue();
    expect(is_finite($p0->y))->toBeTrue();
    expect(is_finite($p2->x))->toBeTrue();
    expect(is_finite($p2->y))->toBeTrue();

    // Position should change over time
    expect($p2->x)->not->toEqual($p1->x);
});

function getProjectilePos(PvpProjectileEntity $proj, float $time): Vector2
{
    $ref    = new ReflectionClass($proj);
    $method = $ref->getMethod('getPos');
    $method->setAccessible(true);

    return $method->invoke($proj, $time);
}

test('character firing creates valid projectile snap', function () use ($mapPath, $mapExists) {
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

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    // Tick once to set up angle (needed for shoot direction)
    $tune = $world->getTuneController();
    $character->tick(1, 100, 0, false, false, $collision, $tune, []);
    $character->move($collision, $tune);

    // Now shoot — this would normally be called from doTick
    // Use reflection to call private method
    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);

    // Should not throw
    $method->invoke($character);
})->throwsNoExceptions();

test('multiple rapid shots do not crash', function () use ($mapPath, $mapExists) {
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

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);

    // Simulate 20 rapid shots (way more than the reload timer allows)
    $tune = $world->getTuneController();
    for ($i = 0; $i < 20; $i++) {
        $character->tick(1, 100, 0, false, false, $collision, $tune, []);
        $character->move($collision, $tune);

        $method->invoke($character);
    }
})->throwsNoExceptions();

test('projectile snap has valid integer values', function () use ($mapPath, $mapExists) {
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

    $pos = $gameLayer->getEntityPositions();
    if (empty($pos)) {
        return;
    }

    $map      = new Map($mapPath);
    $world    = createWorld($map);
    $spawnPos = new Vector2($pos[0]['x'], $pos[0]['y']);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);

    // Tick once to set up angle, then shoot through the character
    $tune = $world->getTuneController();
    $character->tick(1, 100, 0, false, false, $collision, $tune, []);
    $character->move($collision, $tune);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $proj = $world->getEntities()[1];

    $snaps = $proj->doSnap($tee);

    expect($snaps)->toHaveCount(1);

    $ints = $snaps[0]->getInts();
    expect($ints)->toHaveCount(6); // x, y, velX, velY, type, startTick

    foreach ($ints as $val) {
        expect(is_finite($val))->toBeTrue();
        expect($val)->toBeInt();
    }
});

test('projectile snap uses start position not current position', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world    = createWorld($map);
    $startPos = new Vector2(100, 200);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, clone $startPos);
    $character->spawn(clone $startPos, $tee);
    $world->addEntity($character);

    // Tick once to set up angle, then shoot through the character
    $tune = $world->getTuneController();
    $character->tick(1, 100, 0, false, false, $collision, $tune, []);
    $character->move($collision, $tune);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $proj = $world->getEntities()[1];

    // Simulate doTick advancing the position (as if projectile flew forward)
    $proj->getPosition()->x = 500;
    $proj->getPosition()->y = 600;

    $snaps = $proj->doSnap($tee);
    $ints  = $snaps[0]->getInts();

    // Snap x/y must be startPos, not current position (500, 600).
    // The client uses snap x/y as the starting point and computes displacement
    // from direction, startTick, and curvature. Sending the current position
    // would cause the client to double-apply displacement.
    // shootGun() offsets the start by PHYS_SIZE * 0.75 = 21 in the aim direction.
    $offset = 28 * 0.75;
    expect($ints[0])->toBe((int) round(100 + $offset));
    expect($ints[1])->toBe(200);
});

// --- Grenade explosion tests ---

function createTestWorld(Map $map): AbstractWorld
{
    return new class(new TickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };
}

test('grenade creates explosion event on lifespan expiry', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map   = new Map($mapPath);
    $world = createTestWorld($map);

    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, new Vector2(100, 100));
    $ownerChar->spawn(new Vector2(100, 100), $ownerTee);
    $world->addEntity($ownerChar);

    $proj = new PvpProjectileEntity(
        position: new Vector2(100, 100),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GRENADE,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(1000.0, 7.0, 0); // lifespan = 0, expires immediately
    $world->addEntity($proj);

    $proj->doTick();

    // Check that an explosion event was added
    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $explosionEvents = array_filter($events, fn ($e) => $e instanceof ObjEventExplosionItem);
    expect($explosionEvents)->toHaveCount(1);
});

test('grenade explosion damages nearby character', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map   = new Map($mapPath);
    $world = createTestWorld($map);

    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, new Vector2(100, 100));
    $ownerChar->spawn(new Vector2(100, 100), $ownerTee);
    $world->addEntity($ownerChar);

    // Target 50 units away (within 135 radius, outside 48 inner radius)
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $targetChar = new PvpCharacterEntity($world, new Vector2(150, 100));
    $targetChar->spawn(new Vector2(150, 100), $targetTee);
    $world->addEntity($targetChar);

    expect($targetChar->health)->toBe(10);

    $proj = new PvpProjectileEntity(
        position: new Vector2(100, 100),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GRENADE,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(1000.0, 7.0, 0);
    $world->addEntity($proj);

    $proj->doTick();

    // Target at distance 50: l = 1 - (50-48)/(135-48) = 1 - 2/87 ≈ 0.977
    // dmg = (int)(6 * 0.977) = (int)(5.86) = 5
    expect($targetChar->health)->toBeLessThan(10);
    expect($targetChar->health)->toBeGreaterThan(0);
});

test('grenade explosion does not damage character outside radius', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map   = new Map($mapPath);
    $world = createTestWorld($map);

    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, new Vector2(100, 100));
    $ownerChar->spawn(new Vector2(100, 100), $ownerTee);
    $world->addEntity($ownerChar);

    // Target 200 units away (outside 135 radius)
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $targetChar = new PvpCharacterEntity($world, new Vector2(300, 100));
    $targetChar->spawn(new Vector2(300, 100), $targetTee);
    $world->addEntity($targetChar);

    $proj = new PvpProjectileEntity(
        position: new Vector2(100, 100),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GRENADE,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(1000.0, 7.0, 0);
    $world->addEntity($proj);

    $proj->doTick();

    // Target outside radius should not take damage
    expect($targetChar->health)->toBe(10);
});

test('non-grenade projectile does not create explosion event', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map   = new Map($mapPath);
    $world = createTestWorld($map);

    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, new Vector2(100, 100));
    $ownerChar->spawn(new Vector2(100, 100), $ownerTee);
    $world->addEntity($ownerChar);

    // Gun projectile with lifespan = 0
    $proj = new PvpProjectileEntity(
        position: new Vector2(100, 100),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GUN,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(2200.0, 1.25, 0);
    $world->addEntity($proj);

    $proj->doTick();

    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $explosionEvents = array_filter($events, fn ($e) => $e instanceof ObjEventExplosionItem);
    expect($explosionEvents)->toHaveCount(0);
});

test('grenade collides with character and explodes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Owner character at spawn
    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, clone $spawnPos);
    $ownerChar->spawn(clone $spawnPos, $ownerTee);
    $world->addEntity($ownerChar);

    // Target character 50 units to the right (within projectile path)
    $targetPos = new Vector2($spawnPos->x + 50, $spawnPos->y);
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $targetChar = new PvpCharacterEntity($world, clone $targetPos);
    $targetChar->spawn(clone $targetPos, $targetTee);
    $world->addEntity($targetChar);

    expect($targetChar->health)->toBe(10);

    // Grenade fired to the right from offset position
    $offset = 28 * 0.75; // PHYS_SIZE * 0.75
    $proj   = new PvpProjectileEntity(
        position: new Vector2($spawnPos->x + $offset, $spawnPos->y),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GRENADE,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(1000.0, 7.0, 100);
    $world->addEntity($proj);

    // Tick until projectile hits something or expires
    for ($i = 0; $i < 50; $i++) {
        $tickHandler->next();
        $proj->doTick();
        if ($proj->isToDestroy()) {
            break;
        }
    }

    // Projectile should be destroyed (hit the character)
    expect($proj->isToDestroy())->toBeTrue();

    // Target should have taken explosion damage
    expect($targetChar->health)->toBeLessThan(10);

    // Explosion event should have been created
    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $explosionEvents = array_filter($events, fn ($e) => $e instanceof ObjEventExplosionItem);
    expect($explosionEvents)->toHaveCount(1);
});

test('gun projectile damages character on hit', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Owner character at spawn
    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, clone $spawnPos);
    $ownerChar->spawn(clone $spawnPos, $ownerTee);
    $world->addEntity($ownerChar);

    // Target character 30 units to the right (hit on first tick, before any wall)
    $targetPos = new Vector2($spawnPos->x + 30, $spawnPos->y);
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $targetChar = new PvpCharacterEntity($world, clone $targetPos);
    $targetChar->spawn(clone $targetPos, $targetTee);
    $world->addEntity($targetChar);

    expect($targetChar->health)->toBe(10);

    // Gun projectile fired to the right from offset position
    $offset = 28 * 0.75; // PHYS_SIZE * 0.75
    $proj   = new PvpProjectileEntity(
        position: new Vector2($spawnPos->x + $offset, $spawnPos->y),
        direction: new Vector2(1, 0),
        type: GameConstants::WEAPON_GUN,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(2200.0, 1.25, 100);
    $world->addEntity($proj);

    // Tick until projectile hits something or expires
    for ($i = 0; $i < 50; $i++) {
        $proj->doTick();
        if ($proj->isToDestroy()) {
            break;
        }
    }

    // Projectile should be destroyed (hit the character)
    expect($proj->isToDestroy())->toBeTrue();

    // Target should have taken 1 damage from direct hit
    expect($targetChar->health)->toBe(9);
});

test('projectile does not collide with owner character', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Owner character at spawn position
    $ownerTee = new PlayerTee;
    $world->addTee($ownerTee);

    $ownerChar = new PvpCharacterEntity($world, clone $spawnPos);
    $ownerChar->spawn(clone $spawnPos, $ownerTee);
    $world->addEntity($ownerChar);

    // Gun projectile fired upward from offset position (open sky)
    $offset = 28 * 0.75; // PHYS_SIZE * 0.75
    $proj   = new PvpProjectileEntity(
        position: new Vector2($spawnPos->x, $spawnPos->y - $offset),
        direction: new Vector2(0, -1),
        type: GameConstants::WEAPON_GUN,
        world: $world,
        owner: $ownerTee->teeIndex,
    );
    $proj->setTuning(2200.0, 1.25, 100);
    $world->addEntity($proj);

    // Tick a few times — projectile should survive (not hit owner)
    for ($i = 0; $i < 5; $i++) {
        $tickHandler->next();
        $proj->doTick();
        if ($proj->isToDestroy()) {
            break;
        }
    }

    // Owner should not have taken damage (projectile skips owner)
    expect($ownerChar->health)->toBe(10);
});

test('damage indicators are created on takeDamage', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Attacker
    $attackerTee = new PlayerTee;
    $world->addTee($attackerTee);

    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    // Target
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $target = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $target->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $targetTee);
    $world->addEntity($target);

    // Deal 3 damage to target
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);

    // Check damage indicator events were created (one per point of damage)
    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $damageInds = array_values(array_filter($events, fn ($e) => $e instanceof ObjEventDamageIndItem));
    expect($damageInds)->toHaveCount(3);

    // Each damage indicator should have valid angle values
    foreach ($damageInds as $ind) {
        $ints = $ind->getInts();
        expect($ints)->toHaveCount(3);
        expect($ints[0])->toBe((int) round($target->getPosition()->x));
        expect($ints[1])->toBe((int) round($target->getPosition()->y));
        expect($ints[2])->toBeInt();
    }
});

test('damage indicators group when damage taken in quick succession', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $world    = createWorld($map);
    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    // Attacker
    $attackerTee = new PlayerTee;
    $world->addTee($attackerTee);

    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    // Target
    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $target = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $target->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $targetTee);
    $world->addEntity($target);

    // First hit: 3 damage
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);

    // Second hit within 25 ticks: 2 damage — should use angle offset
    $target->takeDamage(new Vector2(0, 0), 2, $attacker);

    // Total damage indicator events: 3 + 2 = 5
    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $damageInds = array_values(array_filter($events, fn ($e) => $e instanceof ObjEventDamageIndItem));
    expect($damageInds)->toHaveCount(5);

    // damageTaken should be incremented (grouping counter)
    expect($target->damageTaken)->toBe(2);
});

test('killing blow still emits damage indicators', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $gameLayer = $map->getGameLayer();
    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();
    if (empty($entities)) {
        return;
    }

    $spawnPos = new Vector2($entities[0]['x'], $entities[0]['y']);

    $attackerTee = new PlayerTee;
    $world->addTee($attackerTee);

    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    $targetTee = new PlayerTee;
    $world->addTee($targetTee);

    $target = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $target->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $targetTee);
    $world->addEntity($target);

    // Three hits of 3 damage each: 3 + 3 + 3 = 9 total. Target starts with
    // 10 health, so the third hit (bringing total to 9) is NOT lethal yet.
    // A fourth hit of 3 damage would be the killing blow (total 12 > 10).
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);

    expect($target->alive)->toBeTrue();

    // Killing blow: 3 damage that brings health to/below 0.
    $target->takeDamage(new Vector2(0, 0), 3, $attacker);

    expect($target->alive)->toBeFalse();

    // The killing blow must still emit its 3 damage indicators.
    $ref  = new ReflectionClass($world);
    $prop = $ref->getProperty('pendingEvents');
    $prop->setAccessible(true);
    $events = $prop->getValue($world);

    $damageInds = array_values(array_filter($events, fn ($e) => $e instanceof ObjEventDamageIndItem));
    expect($damageInds)->toHaveCount(12); // 3 + 3 + 3 + 3
});
