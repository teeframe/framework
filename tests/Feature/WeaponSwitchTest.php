<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\CharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;

/**
 * Helper: invoke a private method on CharacterEntity via reflection.
 */
function invokePrivate(CharacterEntity $char, string $method, mixed ...$args): mixed
{
    $ref = new ReflectionClass(CharacterEntity::class);
    return $ref->getMethod($method)->invoke($char, ...$args);
}

/**
 * Helper: create a character with a mock world so doTick() doesn't return early.
 */
function createCharacterWithWorld(PlayerTee $tee): CharacterEntity
{
    $mapPath = __DIR__ . '/../../../teeworlds/data/maps/dm1.map';
    if (! file_exists($mapPath)) {
        throw new \RuntimeException('Map file not found: ' . $mapPath);
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();

    // Create a minimal mock world
    $world = new class('test', new \TeeFrame\Core\TickHandler, $map) extends AbstractWorld
    {
        public function __construct(
            string $identifier,
            \TeeFrame\Core\TickHandler $tickHandler,
            Map $map,
        ) {
            parent::__construct($identifier, $tickHandler, $map);
        }

        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };

    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    // Inject world via reflection (protected property from AbstractEntity)
    $ref = new ReflectionClass($character);
    $prop = $ref->getProperty('world');
    $prop->setValue($character, $world);

    return $character;
}

// --- Spawn state ---

test('character spawns with hammer and gun, gun active', function () {
    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    expect($character->lastWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
    expect($character->aWeapons[CharacterEntity::WEAPON_HAMMER]['got'])->toBeTrue();
    expect($character->aWeapons[CharacterEntity::WEAPON_GUN]['got'])->toBeTrue();
    expect($character->aWeapons[CharacterEntity::WEAPON_SHOTGUN]['got'])->toBeFalse();
    expect($character->aWeapons[CharacterEntity::WEAPON_GRENADE]['got'])->toBeFalse();
    expect($character->aWeapons[CharacterEntity::WEAPON_RIFLE]['got'])->toBeFalse();
    expect($character->aWeapons[CharacterEntity::WEAPON_NINJA]['got'])->toBeFalse();
});

// --- Direct weapon selection ---

test('direct weapon selection switches to hammer', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Direct select hammer (1-indexed: 1 = hammer)
    $tee->inputWantedWeapon = 1;

    $character->doTick();

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
    expect($character->lastWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

test('direct weapon selection to unowned weapon is ignored', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Try to select shotgun (3) which we don't have
    $tee->inputWantedWeapon = 3;

    $character->doTick();

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

// --- Next weapon cycling ---

test('next weapon cycles to hammer from gun', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Press next weapon once
    $tee->inputNextWeapon = 1;
    $tee->prevInputNextWeapon = 0;

    $character->doTick();

    // Gun(1) -> next owned: Hammer(0)
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
});

test('next weapon skips unowned weapons', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give shotgun so we can test skipping
    $character->giveWeapon(CharacterEntity::WEAPON_SHOTGUN, 10);

    // Switch to hammer first, then press next twice
    // Hammer(0) -> Gun(1) -> Shotgun(2)
    $tee->inputWantedWeapon = 1; // hammer
    $character->doTick();
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);

    // Save prev state
    $tee->prevInputNextWeapon = $tee->inputNextWeapon;

    // Press next twice (need cur=3 for 2 presses: 0→1 press, 1→2 release, 2→3 press)
    $tee->inputNextWeapon = 3;

    $character->doTick();

    // Hammer(0) -> next owned: Gun(1) -> next owned: Shotgun(2)
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_SHOTGUN);
});

// --- Previous weapon cycling ---

test('prev weapon cycles from gun to hammer', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Press prev weapon once
    $tee->inputPrevWeapon = 1;
    $tee->prevInputPrevWeapon = 0;

    $character->doTick();

    // Gun(1) -> prev owned: Hammer(0)
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
});

test('prev weapon wraps around from hammer to gun', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Switch to hammer first
    $tee->inputWantedWeapon = 1;
    $character->doTick();
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);

    // Save prev state
    $tee->prevInputPrevWeapon = $tee->inputPrevWeapon;

    // Press prev once
    $tee->inputPrevWeapon = 1;

    $character->doTick();

    // Hammer(0) -> prev owned: wraps to Gun(1)
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_GUN);
});

// --- CountInput press detection ---

test('countInput detects single press', function () {
    // Use reflection to test private method
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = new CharacterEntity(new Vector2(0, 0));

    // prev=0, cur=1: one press (transition 0->1, bit 1 is set = press)
    $presses = $method->invoke($character, 0, 1);
    expect($presses)->toBe(1);
});

test('countInput detects multiple presses', function () {
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = new CharacterEntity(new Vector2(0, 0));

    // prev=0, cur=5: transitions 0->1(press), 1->2(release), 2->3(press), 3->4(release), 4->5(press)
    // = 3 presses
    $presses = $method->invoke($character, 0, 5);
    expect($presses)->toBe(3);
});

test('countInput returns zero for no change', function () {
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = new CharacterEntity(new Vector2(0, 0));

    $presses = $method->invoke($character, 5, 5);
    expect($presses)->toBe(0);
});

test('countInput wraps at INPUT_STATE_MASK', function () {
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = new CharacterEntity(new Vector2(0, 0));

    // prev=63 (0x3F), cur=1: wraps 63->0(release), 0->1(press) = 1 press
    $presses = $method->invoke($character, 63, 1);
    expect($presses)->toBe(1);
});

// --- Reload guard ---

test('weapon switch is blocked during reload', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Fire to trigger reload
    $tee->inputFire = true;
    $character->doTick();
    expect($character->reloadTimer)->toBeGreaterThan(0);

    // Try to switch during reload
    $tee->inputWantedWeapon = 1; // hammer
    $tee->inputFire = false;

    $character->doTick();

    // Should still be gun because reload blocks switch
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    // But weapon should be queued
    expect($character->queuedWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
});

test('queued weapon executes after reload ends', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Fire to trigger reload
    $tee->inputFire = true;
    $character->doTick();

    // Queue hammer during reload
    $tee->inputWantedWeapon = 1;
    $tee->inputFire = false;
    $character->doTick();
    expect($character->queuedWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);

    // Tick until reload ends
    while ($character->reloadTimer > 0) {
        $character->doTick();
    }

    // Now fire again — doWeaponSwitch should execute before firing
    $tee->inputFire = true;
    $character->doTick();

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
});

// --- Ninja lock ---

test('cannot switch away from ninja', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give ninja
    $character->aWeapons[CharacterEntity::WEAPON_NINJA] = ['got' => true, 'ammo' => -1];
    $character->activeWeapon = CharacterEntity::WEAPON_NINJA;

    // Try to switch to hammer
    $tee->inputWantedWeapon = 1;
    $character->doTick();

    // Should stay on ninja
    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_NINJA);
});

// --- giveWeapon ---

test('giveWeapon grants a new weapon', function () {
    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(CharacterEntity::WEAPON_SHOTGUN, 10);
    expect($result)->toBeTrue();
    expect($character->aWeapons[CharacterEntity::WEAPON_SHOTGUN]['got'])->toBeTrue();
    expect($character->aWeapons[CharacterEntity::WEAPON_SHOTGUN]['ammo'])->toBe(10);
});

test('giveWeapon caps ammo at 10', function () {
    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(CharacterEntity::WEAPON_SHOTGUN, 999);
    expect($result)->toBeTrue();
    expect($character->aWeapons[CharacterEntity::WEAPON_SHOTGUN]['ammo'])->toBe(10);
});

test('giveWeapon returns false when already at max ammo', function () {
    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    // Gun already has 10 ammo from spawn
    $result = $character->giveWeapon(CharacterEntity::WEAPON_GUN, 10);
    expect($result)->toBeFalse();
});

test('giveWeapon returns false for invalid weapon', function () {
    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(-1, 10);
    expect($result)->toBeFalse();

    $result = $character->giveWeapon(99, 10);
    expect($result)->toBeFalse();
});

// --- Snap reflects active weapon ---

test('snap output reflects active weapon after switch', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Switch to hammer
    $tee->inputWantedWeapon = 1;
    $character->doTick();

    $snaps = $character->doSnap($tee);
    expect($snaps)->toHaveCount(1);

    $ints = $snaps[0]->getInts();
    // weapon is the 20th field (index 19, 0-based)
    expect($ints[19])->toBe(CharacterEntity::WEAPON_HAMMER);
});

// --- setWeapon via reflection ---

test('setWeapon updates lastWeapon and clears queuedWeapon', function () {
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('setWeapon');

    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    // Queue a weapon first
    $character->queuedWeapon = CharacterEntity::WEAPON_HAMMER;

    $method->invoke($character, CharacterEntity::WEAPON_HAMMER);

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_HAMMER);
    expect($character->lastWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

test('setWeapon is no-op for same weapon', function () {
    $ref = new ReflectionClass(CharacterEntity::class);
    $method = $ref->getMethod('setWeapon');

    $tee = new PlayerTee;
    $character = new CharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    $oldLastWeapon = $character->lastWeapon;
    $character->queuedWeapon = CharacterEntity::WEAPON_HAMMER;

    $method->invoke($character, CharacterEntity::WEAPON_GUN); // same as active

    expect($character->activeWeapon)->toBe(CharacterEntity::WEAPON_GUN);
    expect($character->lastWeapon)->toBe($oldLastWeapon);
    expect($character->queuedWeapon)->toBe(CharacterEntity::WEAPON_HAMMER); // unchanged
});