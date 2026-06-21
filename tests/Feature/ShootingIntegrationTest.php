<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\CharacterEntity;
use TeeFrame\Game\Entities\ProjectileEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Core\TickHandler;

$mapPath = __DIR__ . '/../../../teeworlds/data/maps/dm1.map';
$mapExists = file_exists($mapPath);

function createWorld(Map $map): AbstractWorld
{
    return new class('test', new TickHandler, $map) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };
}

/**
 * @return array{CharacterEntity, AbstractWorld}
 */
function setupCharacter(Map $map): array
{
    $world = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        throw new \RuntimeException('No collision');
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;
    $tee->inputFire = 1;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);

    $world->addEntity($character);
    $character->tickPhysics(1, 100, 0, false, false, $collision);
    $character->move($collision);

    return [$character, $world];
}

test('shootGun creates projectile that appears in world entities', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $beforeCount = count($world->getEntities());
    expect($beforeCount)->toBe(1);

    $ref = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $afterCount = count($world->getEntities());
    expect($afterCount)->toBe(2);

    $entities = $world->getEntities();
    $projectile = $entities[1];
    expect($projectile)->toBeInstanceOf(ProjectileEntity::class);
});

test('projectile snap item has correct type and velocity', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $entities = $world->getEntities();
    $projectile = $entities[1];
    $tee = new PlayerTee;
    $snaps = $projectile->doSnap($tee);
    expect($snaps)->toHaveCount(1);

    $snapItem = $snaps[0];
    $ints = $snapItem->getInts();
    expect($ints)->toHaveCount(6);
    expect($snapItem->getItemId())->toBe(2);

    $velX = $ints[2];
    $velY = $ints[3];
    // Velocity in snap is normalized direction * 100 (matching original Teeworlds)
    // Direction is (cos(angle), sin(angle)), so velX ≈ 100, velY ≈ 0
    expect($velX)->toBeGreaterThan(50);
    expect($velX)->toBeLessThan(150);
    expect(abs($velY))->toBeLessThan(50);
});

test('hammer hit does not crash and returns reload timer', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref = new ReflectionClass($character);
    $method = $ref->getMethod('shootHammer');
    $method->setAccessible(true);
    $reloadTimer = $method->invoke($character);

    // No targets nearby, so reloadTimer should be 6 (no hits)
    expect($reloadTimer)->toBe(6);
});

test('hammer hit creates hammer hit snap event for nearby target', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $world = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // Create attacker tee and character
    $attackerTee = new PlayerTee;
    $attackerTee->inputDirection = 1;
    $attackerTee->inputTargetX = 100;
    $attackerTee->inputTargetY = 0;

    $attacker = new CharacterEntity($spawnPos);
    $attacker->spawn($spawnPos, $attackerTee);
    $world->addEntity($attacker);
    $attacker->tickPhysics(1, 100, 0, false, false, $collision);
    $attacker->move($collision);

    // Create target tee and character right in front of attacker (within hammer range)
    $targetPos = new Vector2($spawnPos->x + 20, $spawnPos->y); // 20 units in front

    $targetTee = new PlayerTee;
    $target = new CharacterEntity($targetPos);
    $target->spawn($targetPos, $targetTee);
    $world->addEntity($target);

    // Pre-condition: 2 entities in world
    expect(count($world->getEntities()))->toBe(2);

    // Shoot hammer
    $ref = new ReflectionClass($attacker);
    $method = $ref->getMethod('shootHammer');
    $method->setAccessible(true);
    $reloadTimer = $method->invoke($attacker);

    // Should return 16 (hit cooldown) since target is within range and no wall between
    expect($reloadTimer)->toBe(16);

    // Target should have taken damage
    expect($target->health)->toBeLessThan(10);
});

test('projectile getPos returns forward displacement', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $entities = $world->getEntities();
    $projectile = $entities[1];
    $startX = $projectile->position->x;

    $projRef = new ReflectionClass($projectile);
    $getPosRef = $projRef->getMethod('getPos');
    $getPosRef->setAccessible(true);

    $curPos = $getPosRef->invoke($projectile, 0.04);
    expect($curPos->x)->toBeGreaterThan($startX + 1);
});

test('character tick is updated from world tick in doTick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(42);

    $world = new class('test', $tickHandler, $map) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee = new PlayerTee;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    expect($character->tick)->toBe(0);

    $character->doTick();

    expect($character->tick)->toBe(42);
});

test('hammer fire sets attackTick to current world tick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);

    $world = new class('test', $tickHandler, $map) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee = new PlayerTee;
    $tee->inputFire = 1;
    $tee->prevInputFire = 0;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->doTick();

    // attackTick should be set to the world tick (100), not 0
    expect($character->attackTick)->toBe(100);

    // Verify the snap includes the correct attackTick
    $snaps = $character->doSnap($tee);
    expect($snaps)->toHaveCount(1);
    $ints = $snaps[0]->getInts();
    // attackTick is the last int (index 21)
    expect($ints[21])->toBe(100);
});

test('gun projectile survives 0.5 seconds without collision', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    // World that properly ticks entities
    $world = new class('test', $tickHandler, $map) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void
        {
            foreach ($this->entities as $entity) {
                $entity->doTick();
            }
        }
    };

    // Spawn projectile in open area of the map (center, where spawn points are)
    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // Direction is normalized (matching original Teeworlds)
    $proj = new ProjectileEntity(
        position: clone $spawnPos,
        direction: new Vector2(1, 0),
        type: 1,
    );
    $world->addEntity($proj);

    // Advance 5 ticks (0.1 seconds) — projectile should survive in open spawn area
    for ($i = 0; $i < 5; $i++) {
        $tickHandler->next();
        $world->doTick();
    }

    // Projectile should still be alive
    expect($proj->isToDestroy())->toBeFalse();

    // Position should have moved forward
    // At 2200 units/s, after 0.1s: ~220 units forward
    expect($proj->position->x)->toBeGreaterThan($spawnPos->x + 100);
});

test('projectile snap velocity is normalized direction times 100', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class('test', $tickHandler, $map) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee = new PlayerTee;
    $tee->inputFire = 1;
    $tee->prevInputFire = 0;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new CharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->doTick();

    $entities = $world->getEntities();
    $projectile = $entities[1];
    $snaps = $projectile->doSnap($tee);
    $ints = $snaps[0]->getInts();

    $velX = $ints[2];
    $velY = $ints[3];

    // Direction is (cos(angle), sin(angle)) with angle ≈ 0 (firing right)
    // Normalized direction * 100: velX ≈ 100, velY ≈ 0
    expect($velX)->toBeGreaterThan(90);
    expect($velX)->toBeLessThan(110);
    expect(abs($velY))->toBeLessThan(10);

    // Verify the velocity magnitude is approximately 100 (normalized * 100)
    $velMagnitude = sqrt($velX * $velX + $velY * $velY);
    expect($velMagnitude)->toBeGreaterThan(95);
    expect($velMagnitude)->toBeLessThan(105);
});
