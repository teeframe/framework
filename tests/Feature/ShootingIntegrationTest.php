<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\PickupSpawner;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkMessages;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

/**
 * @return array{PvpCharacterEntity, AbstractWorld}
 */
function setupCharacter(Map $map): array
{
    $world     = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        throw new RuntimeException('No collision');
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);

    $world->addEntity($character);
    $tune = $world->getTuneController();
    $character->tick(1, 100, 0, false, false, $collision, $tune, []);
    $character->move($collision, $tune);

    return [$character, $world];
}

test('shootGun creates projectile that appears in world entities', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map                 = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $beforeCount = count($world->getEntities());
    expect($beforeCount)->toBe(1);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $afterCount = count($world->getEntities());
    expect($afterCount)->toBe(2);

    $entities   = $world->getEntities();
    $projectile = $entities[1];
    expect($projectile)->toBeInstanceOf(PvpProjectileEntity::class);
});

test('projectile snap item has correct type and velocity', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map                 = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $entities   = $world->getEntities();
    $projectile = $entities[1];
    $tee        = new PlayerTee;
    $snaps      = $projectile->doSnap($tee);
    expect($snaps)->toHaveCount(1);

    $snapItem = $snaps[0];
    $ints     = $snapItem->getInts();
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

    $map                 = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref    = new ReflectionClass($character);
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

    $map       = new Map($mapPath);
    $world     = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // Create attacker tee and character
    $attackerTee = new PlayerTee;

    $attacker = new PvpCharacterEntity($world, $spawnPos);
    $attacker->spawn($spawnPos, $attackerTee);
    $world->addEntity($attacker);
    $tune = $world->getTuneController();
    $attacker->tick(1, 100, 0, false, false, $collision, $tune, []);
    $attacker->move($collision, $tune);

    // Create target tee and character right in front of attacker (within hammer range)
    $targetPos = new Vector2($spawnPos->x + 20, $spawnPos->y); // 20 units in front

    $targetTee = new PlayerTee;
    $target    = new class($world, $targetPos) extends PvpCharacterEntity {};
    $target->spawn($targetPos, $targetTee);
    $world->addEntity($target);

    // Pre-condition: 2 entities in world
    expect(count($world->getEntities()))->toBe(2);

    // Shoot hammer
    $ref    = new ReflectionClass($attacker);
    $method = $ref->getMethod('shootHammer');
    $method->setAccessible(true);
    $reloadTimer = $method->invoke($attacker);

    // Should return 16 (hit cooldown) since target is within range and no wall between
    expect($reloadTimer)->toBe(16);

    // Target should have taken damage
    expect($target->health)->toBeLessThan(10);
});

test('hammer hits target at edge of reach range', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
    $world     = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // Create attacker looking right
    $attackerTee = new PlayerTee;

    $attacker = new PvpCharacterEntity($world, $spawnPos);
    $attacker->spawn($spawnPos, $attackerTee);
    $world->addEntity($attacker);
    $tune = $world->getTuneController();
    $attacker->tick(1, 100, 0, false, false, $collision, $tune, []);
    $attacker->move($collision, $tune);

    // ProjStartPos = spawnPos + right * PHYS_SIZE * 0.75 = spawnPos + right * 21
    // Hit radius = PHYS_SIZE * 1.5 = 42
    // Max reach from spawnPos: 21 + 42 = 63
    // Place target at spawnPos + 50 (well within max range, but beyond the old 14+21=35 range)
    $targetPos = new Vector2($spawnPos->x + 50, $spawnPos->y);

    $targetTee = new PlayerTee;
    $target    = new class($world, $targetPos) extends PvpCharacterEntity {};
    $target->spawn($targetPos, $targetTee);
    $world->addEntity($target);

    // Shoot hammer
    $ref    = new ReflectionClass($attacker);
    $method = $ref->getMethod('shootHammer');
    $method->setAccessible(true);
    $reloadTimer = $method->invoke($attacker);

    // Should hit (return 16 = hit cooldown)
    expect($reloadTimer)->toBe(16);
    expect($target->health)->toBeLessThan(10);
});

test('hammer does not hit target beyond reach range', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map       = new Map($mapPath);
    $world     = createWorld($map);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // Create attacker looking right
    $attackerTee = new PlayerTee;

    $attacker = new PvpCharacterEntity($world, $spawnPos);
    $attacker->spawn($spawnPos, $attackerTee);
    $world->addEntity($attacker);
    $tune = $world->getTuneController();
    $attacker->tick(1, 100, 0, false, false, $collision, $tune, []);
    $attacker->move($collision, $tune);

    // Place target beyond max range (max reach = 21 + 42 = 63 from spawnPos)
    $targetPos = new Vector2($spawnPos->x + 70, $spawnPos->y);

    $targetTee = new PlayerTee;
    $target    = new class($world, $targetPos) extends PvpCharacterEntity {};
    $target->spawn($targetPos, $targetTee);
    $world->addEntity($target);

    // Shoot hammer
    $ref    = new ReflectionClass($attacker);
    $method = $ref->getMethod('shootHammer');
    $method->setAccessible(true);
    $reloadTimer = $method->invoke($attacker);

    // Should NOT hit (return 6 = no-hit cooldown)
    expect($reloadTimer)->toBe(6);
    expect($target->health)->toBe(10);
});

test('projectile getPos returns forward displacement', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map                 = new Map($mapPath);
    [$character, $world] = setupCharacter($map);

    $ref    = new ReflectionClass($character);
    $method = $ref->getMethod('shootGun');
    $method->setAccessible(true);
    $method->invoke($character);

    $entities   = $world->getEntities();
    $projectile = $entities[1];
    $startX     = $projectile->getPosition()->x;

    $projRef   = new ReflectionClass($projectile);
    $getPosRef = $projRef->getMethod('getPos');
    $getPosRef->setAccessible(true);

    $curPos = $getPosRef->invoke($projectile, 0.04);
    expect($curPos->x)->toBeGreaterThan($startX + 1);
});

test('character tick is updated from world tick in doTick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(42);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee      = new PlayerTee;

    $character = new PvpCharacterEntity($world, $spawnPos);
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

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee      = new PlayerTee;

    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    // Feed 2 idle inputs to pass the m_NumInputs > 2 guard
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0]));
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0]));

    // Third tick: fire press (prev=0, cur=1 → 1 press)
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0, 'fire' => 1]));

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

    $map       = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $tickHandler = new TickHandler(0);

    // World that properly ticks entities
    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void
        {
            foreach ($this->entities as $entity) {
                $entity->doTick();
            }
        }
    };

    // Spawn character in open area of the map (center, where spawn points are)
    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;

    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);
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

    // Advance 5 ticks (0.1 seconds) — projectile should survive in open spawn area
    for ($i = 0; $i < 5; $i++) {
        $tickHandler->next();
        $proj->doTick();
        if ($proj->isToDestroy()) {
            break;
        }
    }

    // Projectile should still be alive
    expect($proj->isToDestroy())->toBeFalse();

    // Position should have moved forward
    // At 2200 units/s, after 0.1s: ~220 units forward
    expect($proj->getPosition()->x)->toBeGreaterThan($spawnPos->x + 100);
});

test('projectile snap velocity is normalized direction times 100', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee      = new PlayerTee;

    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    // Feed 2 idle inputs to pass the m_NumInputs > 2 guard
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0]));
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0]));

    // Third tick: fire press (prev=0, cur=1 → 1 press)
    feedInput($character, input(['direction' => 1, 'targetX' => 100, 'targetY' => 0, 'fire' => 1]));

    $entities   = $world->getEntities();
    $projectile = $entities[1];
    $snaps      = $projectile->doSnap($tee);
    $ints       = $snaps[0]->getInts();

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

test('character snap id matches tee index when pickups are present', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    // Add pickups first (simulating PickupSpawner)
    $gameLayer = $map->getGameLayer();
    if ($gameLayer !== null) {
        PickupSpawner::spawn($world, $gameLayer);
    }

    // Now add a player character
    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $tee      = new PlayerTee;
    $world->addTee($tee);

    $character = new PvpCharacterEntity($world, $spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    // Set viewPosition to match character position (distance culling)
    $tee->viewPosition = clone $spawnPos;

    // Get the world snap
    $snaps = $world->doSnap($tee);

    // Find the character snap item
    $charSnaps = array_filter($snaps, fn ($s) => $s->getItemId() === NetworkMessages::NETOBJTYPE_CHARACTER);
    expect($charSnaps)->not->toBeEmpty();

    $charSnap = array_values($charSnaps)[0];

    // The character snap ID must match the tee's index (client ID),
    // otherwise the DDNet client won't recognize it as the local player.
    expect($charSnap->getId())->toBe($tee->teeIndex);
});

test('character death sets respawn on tee and notifies game controller', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);

    $deathNotified = false;
    $deathVictim   = null;
    $deathKiller   = -1;

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public int $deathNotifiedCount = 0;

        public function doTick(): void {}
    };

    // Override game controller to track onCharacterDeath calls
    $worldRef = new ReflectionClass($world);
    $gcProp   = $worldRef->getProperty('gameController');
    $gcProp->setAccessible(true);

    $gc = new class($tickHandler) extends AbstractGameController
    {
        public bool $deathCalled = false;

        public ?AbstractCharacterEntity $victim = null;

        public int $killerTeeIndex = -1;

        public function doTick(): void {}

        public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
        {
            $this->deathCalled    = true;
            $this->victim         = $victim;
            $this->killerTeeIndex = $killerTeeIndex;

            return 0;
        }
    };
    $gcProp->setValue($world, $gc);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $attackerTee = new PlayerTee;
    $world->addTee($attackerTee);

    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    $victimTee = new PlayerTee;
    $world->addTee($victimTee);

    $victim = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $victimTee);
    $world->addEntity($victim);

    // Kill the victim
    $victim->takeDamage(new Vector2(0, 0), 10, $attacker);

    // Victim should be dead
    expect($victim->alive)->toBeFalse();

    // Game controller should have been notified
    expect($gc->deathCalled)->toBeTrue();
    expect($gc->victim)->toBe($victim);
    expect($gc->killerTeeIndex)->toBe($attackerTee->teeIndex);

    // Tee should be set to respawn
    expect($victimTee->spawning)->toBeTrue();
    expect($victimTee->respawnTick)->toBe($tickHandler->get() + 25); // 0.5s = 25 ticks
});

test('tee index is reused on reconnect and snap id matches', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    // First connection: tee gets index 0
    $tee1 = new PlayerTee;
    $world->addTee($tee1);
    expect($tee1->teeIndex)->toBe(0);

    $char1 = new PvpCharacterEntity($world, clone $spawnPos);
    $char1->spawn(clone $spawnPos, $tee1);
    $world->addEntity($char1);

    // Set viewPosition to character position to avoid distance culling
    $tee1->viewPosition = clone $spawnPos;

    // Snap ID must match tee index
    $worldSnaps = $world->doSnap($tee1);
    $charSnap   = array_values(array_filter($worldSnaps, fn ($s) => $s->getItemId() === NetworkMessages::NETOBJTYPE_CHARACTER));
    expect($charSnap[0]->getId())->toBe(0);

    $playerInfo = array_values(array_filter($worldSnaps, fn ($s) => $s->getItemId() === NetworkMessages::NETOBJTYPE_PLAYERINFO));
    expect($playerInfo[0]->getId())->toBe(0);

    // Disconnect: remove tee, index 0 released
    $world->removeTee($tee1);

    // Reconnect: new tee should reuse index 0
    $tee2 = new PlayerTee;
    $world->addTee($tee2);
    expect($tee2->teeIndex)->toBe(0);

    $char2 = new PvpCharacterEntity($world, clone $spawnPos);
    $char2->spawn(clone $spawnPos, $tee2);
    $world->addEntity($char2);

    // Set viewPosition to character position to avoid distance culling
    $tee2->viewPosition = clone $spawnPos;

    // Snap ID must still match tee index after reconnect
    $worldSnaps2 = $world->doSnap($tee2);
    $charSnap2   = array_values(array_filter($worldSnaps2, fn ($s) => $s->getItemId() === NetworkMessages::NETOBJTYPE_CHARACTER));
    expect($charSnap2[0]->getId())->toBe(0);

    $playerInfo2 = array_values(array_filter($worldSnaps2, fn ($s) => $s->getItemId() === NetworkMessages::NETOBJTYPE_PLAYERINFO));
    expect($playerInfo2[0]->getId())->toBe(0);
});

/**
 * Helper: create a character with a ticking world for auto-fire tests.
 *
 * @return array{AbstractCharacterEntity, AbstractWorld}
 */
function createCharacterForAutoFire(PlayerTee $tee, int $weapon): array
{
    $mapPath     = __DIR__.'/../dm1.map';
    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(0);

    $world = new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);

    // Give the requested weapon with ammo
    $character->giveWeapon($weapon, 10);
    $character->setWeapon($weapon);

    return [$character, $world];
}

test('grenade auto-fires while fire button is held', function () use ($mapExists) {
    if (! $mapExists) {
        return;
    }

    $tee                 = new PlayerTee;
    [$character, $world] = createCharacterForAutoFire($tee, GameConstants::WEAPON_GRENADE);

    // Feed 2 idle inputs to pass the numInputs > 2 guard
    feedInput($character, input());
    feedInput($character, input());

    // Press fire (fire=1) and keep holding it across multiple ticks.
    // Grenade reload is 25 ticks, so we need to advance past it to see auto-fire.
    feedInput($character, input(['fire' => 1]));

    // First shot fired immediately
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(1);

    // Advance past reload (25 ticks) while still holding fire
    for ($i = 0; $i < 26; $i++) {
        feedInput($character, input(['fire' => 1]));
    }

    // Auto-fire should have fired a second projectile
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(2);
});

test('shotgun auto-fires while fire button is held', function () use ($mapExists) {
    if (! $mapExists) {
        return;
    }

    $tee                 = new PlayerTee;
    [$character, $world] = createCharacterForAutoFire($tee, GameConstants::WEAPON_SHOTGUN);

    feedInput($character, input());
    feedInput($character, input());

    // Press and hold fire
    feedInput($character, input(['fire' => 1]));

    // Shotgun fires 5 projectiles per shot
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(5);

    // Advance past reload (25 ticks) while still holding fire
    for ($i = 0; $i < 26; $i++) {
        feedInput($character, input(['fire' => 1]));
    }

    // Auto-fire: second shot, 5 more projectiles
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(10);
});

test('rifle auto-fires while fire button is held', function () use ($mapExists) {
    if (! $mapExists) {
        return;
    }

    $tee                 = new PlayerTee;
    [$character, $world] = createCharacterForAutoFire($tee, GameConstants::WEAPON_RIFLE);

    feedInput($character, input());
    feedInput($character, input());

    // Press and hold fire
    feedInput($character, input(['fire' => 1]));

    // Rifle fires a laser (not a projectile), so check entities count
    $entitiesAfterFirst = count($world->getEntities());
    expect($entitiesAfterFirst)->toBe(2); // character + laser

    // Advance past reload (40 ticks) while still holding fire
    for ($i = 0; $i < 41; $i++) {
        feedInput($character, input(['fire' => 1]));
    }

    // Auto-fire: a second laser was created
    $entitiesAfterSecond = count($world->getEntities());
    expect($entitiesAfterSecond)->toBeGreaterThan($entitiesAfterFirst);
});

test('gun does not auto-fire while fire button is held', function () use ($mapExists) {
    if (! $mapExists) {
        return;
    }

    $tee                 = new PlayerTee;
    [$character, $world] = createCharacterForAutoFire($tee, GameConstants::WEAPON_GUN);

    feedInput($character, input());
    feedInput($character, input());

    // Press and hold fire
    feedInput($character, input(['fire' => 1]));

    // First shot fired
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(1);

    // Advance past reload (6 ticks) while still holding fire (fire stays 1)
    for ($i = 0; $i < 10; $i++) {
        feedInput($character, input(['fire' => 1]));
    }

    // Gun is NOT full-auto: holding fire should not fire again
    $projectiles = array_filter($world->getEntities(), fn ($e) => $e instanceof PvpProjectileEntity);
    expect($projectiles)->toHaveCount(1);
});
