<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

/** @return array{TestWorld, TickHandler, PlayerTee, PvpCharacterEntity} */
function createAmmoRegenerationWorld(): array
{
    $tickHandler = new TickHandler(100);
    $world       = new TestWorld($tickHandler, new Map(__DIR__.'/../dm1.map'));
    $tee         = new PlayerTee;
    $world->addTee($tee);
    $world->doTick();
    $world->clearEvents();
    $tickHandler->next();
    assert($tee->character instanceof PvpCharacterEntity);
    $character = $tee->character;

    // Pass the initial input guard using the same buffered-input path as the server.
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());

    return [$world, $tickHandler, $tee, $character];
}

/** @return int[] Sound IDs in this tick's player snapshot. */
function advanceAmmoRegenerationTick(TestWorld $world, TickHandler $tickHandler, PlayerTee $tee, PlayerInput $input): array
{
    $tee->inputs[$tickHandler->get()] = $input;
    $world->doTick();
    $sounds = [];
    foreach ($world->doSnap($tee) as $item) {
        if ($item instanceof ObjEventSoundWorldItem) {
            $sounds[] = $item->soundId;
        }
    }
    $world->clearEvents();
    $tickHandler->next();

    return $sounds;
}

test('an exhausted gun regenerates and fires again while the player keeps clicking', function (int $pressInterval) {
    [$world, $tickHandler, $tee, $character]             = createAmmoRegenerationWorld();
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 1;
    $fireCounter                                         = -1;
    $shotTicks                                           = [];
    $emptyAttempts                                       = 0;

    for ($tick = 0; $tick < 150; $tick++) {
        if ($tick % $pressInterval === 0) {
            // A release and another press advance the 0.6 fire counter by two.
            $fireCounter = ($fireCounter + 2) & PvpCharacterEntity::INPUT_STATE_MASK;
        }
        $currentTick = $tickHandler->get();
        $sounds      = advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => $fireCounter]));
        if (in_array(GameConstants::SOUND_GUN_FIRE, $sounds, true)) {
            $shotTicks[] = $currentTick;
        }
        if (in_array(GameConstants::SOUND_WEAPON_NOAMMO, $sounds, true)) {
            $emptyAttempts++;
        }
        expect($character->alive)->toBeTrue();
    }

    expect($emptyAttempts)->toBeGreaterThan(0);
    // Starting with one bullet, subsequent shots must have used regenerated ammo.
    expect(count($shotTicks))->toBeGreaterThanOrEqual(3);
    for ($i = 1; $i < count($shotTicks); $i++) {
        // Allow reload/press cadence, but never an unbounded no-ammo loop.
        expect($shotTicks[$i] - $shotTicks[$i - 1])
            ->toBeGreaterThanOrEqual(25)
            ->toBeLessThanOrEqual(25 + 12 + $pressInterval);
    }
})->with([
    'press every tick'                        => [1],
    'press every no-ammo cooldown'            => [6],
    'press with idle ticks between cooldowns' => [8],
]);

test('an idle equipped gun regenerates one bullet after 500 milliseconds', function () {
    [$world, $tickHandler, $tee, $character]             = createAmmoRegenerationWorld();
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 0;

    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
    for ($tick = 0; $tick < 24; $tick++) {
        advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(0);
    }
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(1);
});

test('a successful gun shot resets regeneration and waits until after reload to restart it', function () {
    [$world, $tickHandler, $tee, $character]             = createAmmoRegenerationWorld();
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 2;
    $character->ammoRegenStart                           = $tickHandler->get() - 24;

    $sounds = advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
    expect($sounds)->toContain(GameConstants::SOUND_GUN_FIRE);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(1);
    expect($character->ammoRegenStart)->toBe(-1);

    for ($tick = 0; $tick < 5; $tick++) {
        advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
        expect($character->ammoRegenStart)->toBe(-1);
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(1);
    }
    expect($character->reloadTimer)->toBe(0);

    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
    for ($tick = 0; $tick < 24; $tick++) {
        advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(1);
    }
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(2);
});

test('gun regeneration never exceeds ten bullets', function () {
    [$world, $tickHandler, $tee, $character]             = createAmmoRegenerationWorld();
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 9;

    for ($tick = 0; $tick < 100; $tick++) {
        advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
        expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBeLessThanOrEqual(10);
    }
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe(10);
});

test('weapons without regeneration stay empty while the player keeps clicking', function (int $weapon) {
    [$world, $tickHandler, $tee, $character] = createAmmoRegenerationWorld();
    $character->giveWeapon($weapon, 0);
    $character->setWeapon($weapon);
    $world->clearEvents();

    for ($tick = 0; $tick < 75; $tick++) {
        advanceAmmoRegenerationTick($world, $tickHandler, $tee, input([
            'fire' => (1 + 2 * $tick) & PvpCharacterEntity::INPUT_STATE_MASK,
        ]));
    }
    expect($character->weapons[$weapon]->ammo)->toBe(0);
    expect($character->ammoRegenStart)->toBe(-1);
})->with([
    'shotgun' => [GameConstants::WEAPON_SHOTGUN],
    'grenade' => [GameConstants::WEAPON_GRENADE],
    'rifle'   => [GameConstants::WEAPON_RIFLE],
]);

test('holding fire on an empty gun regenerates ammo without making the gun full-auto', function () {
    [$world, $tickHandler, $tee, $character]             = createAmmoRegenerationWorld();
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 0;

    for ($tick = 0; $tick < 75; $tick++) {
        $sounds = advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 1]));
        expect($sounds)->not->toContain(GameConstants::SOUND_GUN_FIRE);
    }
    $ammo = $character->weapons[GameConstants::WEAPON_GUN]->ammo;
    expect($ammo)->toBeGreaterThan(0);

    $sounds = advanceAmmoRegenerationTick($world, $tickHandler, $tee, input(['fire' => 3]));
    expect($sounds)->toContain(GameConstants::SOUND_GUN_FIRE);
    expect($character->weapons[GameConstants::WEAPON_GUN]->ammo)->toBe($ammo - 1);
});
