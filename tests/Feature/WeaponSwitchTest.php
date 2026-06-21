<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\AbstractCharacterEntity;
use TeeFrame\Game\Entities\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;

$makeChar = fn () => new PvpCharacterEntity(new Vector2(0, 0));

/**
 * Helper: invoke a private method on CharacterEntity via reflection.
 */
function invokePrivate(AbstractCharacterEntity $char, string $method, mixed ...$args): mixed
{
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    return $ref->getMethod($method)->invoke($char, ...$args);
}

/**
 * Helper: create a character with a mock world so doTick() doesn't return early.
 */
function createCharacterWithWorld(PlayerTee $tee): AbstractCharacterEntity
{
    $mapPath = __DIR__ . '/../dm1.map';
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

    $character = new PvpCharacterEntity(new Vector2(0, 0));
    $character->spawn(new Vector2(100, 100), $tee);

    // Inject world via reflection (protected property from AbstractEntity)
    $ref = new ReflectionClass($character);
    $prop = $ref->getProperty('world');
    $prop->setValue($character, $world);

    return $character;
}

// --- Spawn state ---

test('character spawns with hammer and gun, gun active', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->lastWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
    expect($character->aWeapons[GameConstants::WEAPON_HAMMER]['got'])->toBeTrue();
    expect($character->aWeapons[GameConstants::WEAPON_GUN]['got'])->toBeTrue();
    expect($character->aWeapons[GameConstants::WEAPON_SHOTGUN]['got'])->toBeFalse();
    expect($character->aWeapons[GameConstants::WEAPON_GRENADE]['got'])->toBeFalse();
    expect($character->aWeapons[GameConstants::WEAPON_RIFLE]['got'])->toBeFalse();
    expect($character->aWeapons[GameConstants::WEAPON_NINJA]['got'])->toBeFalse();
});

// --- Direct weapon selection ---

test('direct weapon selection switches to hammer', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Direct select hammer (1-indexed: 1 = hammer)
    $tee->inputWantedWeapon = 1;

    $character->doTick();

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->lastWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

test('direct weapon selection to unowned weapon is ignored', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Try to select shotgun (3) which we don't have
    $tee->inputWantedWeapon = 3;

    $character->doTick();

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
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
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('next weapon skips unowned weapons', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give shotgun so we can test skipping
    $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 10);

    // Switch to hammer first, then press next twice
    // Hammer(0) -> Gun(1) -> Shotgun(2)
    $tee->inputWantedWeapon = 1; // hammer
    $character->doTick();
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Save prev state
    $tee->prevInputNextWeapon = $tee->inputNextWeapon;

    // Press next twice (need cur=3 for 2 presses: 0→1 press, 1→2 release, 2→3 press)
    $tee->inputNextWeapon = 3;

    $character->doTick();

    // Hammer(0) -> next owned: Gun(1) -> next owned: Shotgun(2)
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_SHOTGUN);
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
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('prev weapon wraps around from hammer to gun', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Switch to hammer first
    $tee->inputWantedWeapon = 1;
    $character->doTick();
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Save prev state
    $tee->prevInputPrevWeapon = $tee->inputPrevWeapon;

    // Press prev once
    $tee->inputPrevWeapon = 1;

    $character->doTick();

    // Hammer(0) -> prev owned: wraps to Gun(1)
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
});

// --- CountInput press detection ---

test('countInput detects single press', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = $makeChar();

    // prev=0, cur=1: one press (transition 0->1, bit 1 is set = press)
    $presses = $method->invoke($character, 0, 1);
    expect($presses)->toBe(1);
});

test('countInput detects multiple presses', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = $makeChar();

    // prev=0, cur=5: transitions 0->1(press), 1->2(release), 2->3(press), 3->4(release), 4->5(press)
    // = 3 presses
    $presses = $method->invoke($character, 0, 5);
    expect($presses)->toBe(3);
});

test('countInput returns zero for no change', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = $makeChar();

    $presses = $method->invoke($character, 5, 5);
    expect($presses)->toBe(0);
});

test('countInput wraps at INPUT_STATE_MASK', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('countInput');

    $character = $makeChar();

    // prev=63 (0x3F), cur=1: wraps 63->0(release), 0->1(press) = 1 press
    $presses = $method->invoke($character, 63, 1);
    expect($presses)->toBe(1);
});

// --- Reload guard ---

test('weapon switch is blocked during reload', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // First tick: syncs prev inputs
    $tee->inputFire = 0;
    $character->doTick();

    // Second tick: fire press (prev=0, cur=1 → 1 press)
    $tee->inputFire = 1;
    $character->doTick();
    expect($character->reloadTimer)->toBeGreaterThan(0);

    // Try to switch during reload (no fire press this tick)
    $tee->inputWantedWeapon = 1; // hammer

    $character->doTick();

    // Should still be gun because reload blocks switch
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    // But weapon should be queued
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('queued weapon executes after reload ends', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // First tick: syncs prev inputs
    $tee->inputFire = 0;
    $character->doTick();

    // Second tick: fire to trigger reload (prev=0, cur=1 → 1 press)
    $tee->inputFire = 1;
    $character->doTick();

    // Queue hammer during reload (no fire press this tick)
    $tee->inputWantedWeapon = 1;
    $character->doTick();
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Tick until reload ends (no fire presses)
    while ($character->reloadTimer > 0) {
        $character->doTick();
    }

    // Now fire again — doWeaponSwitch should execute before firing
    // Need a new fire press: prevInputFire was saved as 1, set to 2
    $tee->inputFire = 2;
    $character->doTick();

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
});

// --- Ninja lock ---

test('cannot switch away from ninja', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give ninja
    $character->aWeapons[GameConstants::WEAPON_NINJA] = ['got' => true, 'ammo' => -1];
    $character->activeWeapon = GameConstants::WEAPON_NINJA;

    // Try to switch to hammer
    $tee->inputWantedWeapon = 1;
    $character->doTick();

    // Should stay on ninja
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_NINJA);
});

// --- giveWeapon ---

test('giveWeapon grants a new weapon', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 10);
    expect($result)->toBeTrue();
    expect($character->aWeapons[GameConstants::WEAPON_SHOTGUN]['got'])->toBeTrue();
    expect($character->aWeapons[GameConstants::WEAPON_SHOTGUN]['ammo'])->toBe(10);
});

test('giveWeapon caps ammo at 10', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 999);
    expect($result)->toBeTrue();
    expect($character->aWeapons[GameConstants::WEAPON_SHOTGUN]['ammo'])->toBe(10);
});

test('giveWeapon returns false when already at max ammo', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    // Gun already has 10 ammo from spawn
    $result = $character->giveWeapon(GameConstants::WEAPON_GUN, 10);
    expect($result)->toBeFalse();
});

test('giveWeapon returns false for invalid weapon', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
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
    expect($ints[19])->toBe(GameConstants::WEAPON_HAMMER);
});

// --- setWeapon via reflection ---

test('setWeapon updates lastWeapon and clears queuedWeapon', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('setWeapon');

    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    // Queue a weapon first
    $character->queuedWeapon = GameConstants::WEAPON_HAMMER;

    $method->invoke($character, GameConstants::WEAPON_HAMMER);

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->lastWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

test('setWeapon is no-op for same weapon', function () use ($makeChar) {
    $ref = new ReflectionClass(AbstractCharacterEntity::class);
    $method = $ref->getMethod('setWeapon');

    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    $oldLastWeapon = $character->lastWeapon;
    $character->queuedWeapon = GameConstants::WEAPON_HAMMER;

    $method->invoke($character, GameConstants::WEAPON_GUN); // same as active

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->lastWeapon)->toBe($oldLastWeapon);
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER); // unchanged
});

// --- No-ammo behavior ---

test('no ammo does not set attackTick', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Deplete gun ammo
    $character->aWeapons[GameConstants::WEAPON_GUN]['ammo'] = 0;

    // First tick: syncs prev inputs (no fire detected)
    $tee->inputFire = 0;
    $character->doTick();

    // Second tick: real fire press (prev=0, cur=1 → 1 press)
    $tee->inputFire = 1;
    $character->doTick();

    // attackTick should NOT be updated (no shot was fired — no ammo)
    expect($character->attackTick)->toBe(0);

    // reloadTimer should be set (click delay)
    expect($character->reloadTimer)->toBeGreaterThan(0);
});

// --- First-tick input sync ---

test('first tick syncs prevInputFire to avoid spurious presses', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Simulate client connecting with fire counter already at 4 (even = not pressing)
    $tee->inputFire = 4;

    $character->doTick();

    // Should NOT fire — prev was synced to cur on first tick
    expect($character->reloadTimer)->toBe(0);
    expect($character->attackTick)->toBe(0);
});

test('second tick detects real fire press after first-tick sync', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // First tick: client sends fire=4 (even, not pressing), prev synced to 4
    $tee->inputFire = 4;
    $character->doTick();
    expect($character->reloadTimer)->toBe(0); // no shot

    // Second tick: client sends fire=5 (odd, pressing — one press: 4→5)
    $tee->inputFire = 5;
    $character->doTick();

    // Should fire — one press detected, reloadTimer set
    expect($character->reloadTimer)->toBeGreaterThan(0);
});
