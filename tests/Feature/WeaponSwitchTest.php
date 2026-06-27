<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\CharacterWeaponState;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;

$makeChar = fn () => new PvpCharacterEntity(createWorld(new Map(__DIR__ . '/../dm1.map')), new Vector2(0, 0));

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
    $world = new class('test', new \TeeFrame\Core\TickHandler, $map, $GLOBALS['mockGameServer']) extends AbstractWorld
    {
        public function __construct(
            string $identifier,
            \TeeFrame\Core\TickHandler $tickHandler,
            Map $map,
            \TeeFrame\Server\AbstractServerInstance $server,
        ) {
            parent::__construct($identifier, $tickHandler, $map, $server);
        }

        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };

    $character = new PvpCharacterEntity(createWorld(new Map(__DIR__ . '/../dm1.map')), new Vector2(0, 0));
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
    expect($character->weapons[GameConstants::WEAPON_HAMMER]->got)->toBeTrue();
    expect($character->weapons[GameConstants::WEAPON_GUN]->got)->toBeTrue();
    expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->got)->toBeFalse();
    expect($character->weapons[GameConstants::WEAPON_GRENADE]->got)->toBeFalse();
    expect($character->weapons[GameConstants::WEAPON_RIFLE]->got)->toBeFalse();
    expect($character->weapons[GameConstants::WEAPON_NINJA]->got)->toBeFalse();
});

// --- Direct weapon selection ---

test('direct weapon selection switches to hammer', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Feed 2 idle inputs to pass the m_NumInputs > 2 guard
    feedInput($character, input());
    feedInput($character, input());

    // Direct select hammer (1-indexed: 1 = hammer)
    feedInput($character, input(['wantedWeapon' => 1]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->lastWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

test('direct weapon selection to unowned weapon is ignored', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    feedInput($character, input());
    feedInput($character, input());

    // Try to select shotgun (3) which we don't have
    feedInput($character, input(['wantedWeapon' => 3]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
});

// --- Next weapon cycling ---

test('next weapon cycles to hammer from gun', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    feedInput($character, input());
    feedInput($character, input());

    // Press next weapon once (prev=0, cur=1 → 1 press)
    feedInput($character, input(['nextWeapon' => 1]));

    // Gun(1) -> next owned: Hammer(0)
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('next weapon skips unowned weapons', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give shotgun so we can test skipping
    $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 10);

    feedInput($character, input());
    feedInput($character, input());

    // Switch to hammer first, then press next twice
    // Hammer(0) -> Gun(1) -> Shotgun(2)
    feedInput($character, input(['wantedWeapon' => 1])); // hammer
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Press next twice (need cur=3 for 2 presses: 0→1 press, 1→2 release, 2→3 press)
    feedInput($character, input(['nextWeapon' => 3]));

    // Hammer(0) -> next owned: Gun(1) -> next owned: Shotgun(2)
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_SHOTGUN);
});

// --- Previous weapon cycling ---

test('prev weapon cycles from gun to hammer', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    feedInput($character, input());
    feedInput($character, input());

    // Press prev weapon once (prev=0, cur=1 → 1 press)
    feedInput($character, input(['prevWeapon' => 1]));

    // Gun(1) -> prev owned: Hammer(0)
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('prev weapon wraps around from hammer to gun', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    feedInput($character, input());
    feedInput($character, input());

    // Switch to hammer first
    feedInput($character, input(['wantedWeapon' => 1]));
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Press prev once (prev=0, cur=1 → 1 press)
    feedInput($character, input(['prevWeapon' => 1]));

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

    feedInput($character, input());
    feedInput($character, input());

    // Third tick: fire press (prev=0, cur=1 → 1 press)
    feedInput($character, input(['fire' => 1]));
    expect($character->reloadTimer)->toBeGreaterThan(0);

    // Try to switch during reload (no fire press this tick)
    feedInput($character, input(['wantedWeapon' => 1])); // hammer

    // Should still be gun because reload blocks switch
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    // But weapon should be queued
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER);
});

test('queued weapon executes after reload ends', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    feedInput($character, input());
    feedInput($character, input());

    // Third tick: fire to trigger reload (prev=0, cur=1 → 1 press)
    feedInput($character, input(['fire' => 1]));

    // Queue hammer during reload (no fire press this tick)
    feedInput($character, input(['wantedWeapon' => 1]));
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER);

    // Tick until reload ends (no fire presses)
    while ($character->reloadTimer > 0) {
        feedInput($character, input());
    }

    // Now fire again — doWeaponSwitch should execute before firing
    // Need a new fire press: prevInputFire was saved as 1, set to 2
    feedInput($character, input(['fire' => 2]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
});

// --- Ninja lock ---

test('cannot switch away from ninja', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Give ninja
    $character->weapons[GameConstants::WEAPON_NINJA] = new CharacterWeaponState(got: true, ammo: -1);
    $character->activeWeapon = GameConstants::WEAPON_NINJA;

    feedInput($character, input());
    feedInput($character, input());

    // Try to switch to hammer
    feedInput($character, input(['wantedWeapon' => 1]));

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
    expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->got)->toBeTrue();
    expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo)->toBe(10);
});

test('giveWeapon caps ammo at 10', function () use ($makeChar) {
    $tee = new PlayerTee;
    $character = $makeChar();
    $character->spawn(new Vector2(100, 100), $tee);

    $result = $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 999);
    expect($result)->toBeTrue();
    expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo)->toBe(10);
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

    feedInput($character, input());
    feedInput($character, input());

    // Switch to hammer
    feedInput($character, input(['wantedWeapon' => 1]));

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
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 0;

    feedInput($character, input());
    feedInput($character, input());

    // Third tick: real fire press (prev=0, cur=1 → 1 press)
    feedInput($character, input(['fire' => 1]));

    // attackTick should NOT be updated (no shot was fired — no ammo)
    expect($character->attackTick)->toBe(0);

    // reloadTimer should be set (click delay)
    expect($character->reloadTimer)->toBeGreaterThan(0);
});

// --- First-tick input sync ---

test('first tick syncs prevInputFire to avoid spurious presses', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // The m_NumInputs > 2 guard ignores weapon switch/fire for the first 2 inputs.
    // Simulate client connecting with fire counter already at 4 (even = not pressing).
    feedInput($character, input(['fire' => 4]));
    feedInput($character, input(['fire' => 4]));

    // Third tick: prev=4, cur=4 → 0 presses (no spurious fire from pre-existing counter)
    feedInput($character, input(['fire' => 4]));

    // Should NOT fire
    expect($character->reloadTimer)->toBe(0);
    expect($character->attackTick)->toBe(0);
});

test('second tick detects real fire press after first-tick sync', function () {
    $tee = new PlayerTee;
    $character = createCharacterWithWorld($tee);

    // Feed 2 inputs with fire=4 to establish baseline (m_NumInputs > 2 guard)
    feedInput($character, input(['fire' => 4]));
    feedInput($character, input(['fire' => 4]));

    // Third tick: client sends fire=4 (even, not pressing), prev=4 → 0 presses
    feedInput($character, input(['fire' => 4]));
    expect($character->reloadTimer)->toBe(0); // no shot

    // Fourth tick: client sends fire=5 (odd, pressing — one press: 4→5)
    feedInput($character, input(['fire' => 5]));

    // Should fire — one press detected, reloadTimer set
    expect($character->reloadTimer)->toBeGreaterThan(0);
});
