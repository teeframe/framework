<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

class CustomAmmoRegenerationCharacter extends PvpCharacterEntity
{
    protected function ammoRegenInterval(): int
    {
        return match ($this->activeWeapon) {
            GameConstants::WEAPON_GUN                                    => 31,
            GameConstants::WEAPON_SHOTGUN, GameConstants::WEAPON_GRENADE => 50,
            GameConstants::WEAPON_RIFLE                                  => 60,
            default                                                      => 0,
        };
    }

    protected function ammoLimit(): int
    {
        return 4;
    }
}

/** @return array{TestWorld, TickHandler, PlayerTee, PvpCharacterEntity} */
function createAmmoRegenerationWorld(bool $custom = false): array
{
    $tickHandler = new TickHandler(100);
    $map         = new Map(__DIR__.'/../dm1.map');
    $world       = $custom ? new class($tickHandler, $map) extends TestWorld
    {
        protected function createCharacterEntity(Vector2 $position): AbstractCharacterEntity
        {
            return new CustomAmmoRegenerationCharacter($this, $position);
        }
    } : new TestWorld($tickHandler, $map);
    $tee = new PlayerTee;
    $world->addTee($tee);
    $world->doTick();
    $world->clearEvents();
    $tickHandler->advanceTick();
    assert($tee->character instanceof PvpCharacterEntity);
    $character = $tee->character;

    // Direct weapon input starts after three predicted inputs (CCharacter::OnDirectInput).
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());
    advanceAmmoRegenerationTick($world, $tickHandler, $tee, input());

    return [$world, $tickHandler, $tee, $character];
}

/** @return int[] Sound IDs in this tick's player snapshot. */
function advanceAmmoRegenerationTick(TestWorld $world, TickHandler $tickHandler, PlayerTee $tee, PlayerInput $input): array
{
    $tee->onDirectInput($input, $tickHandler->getCurrentTick());
    $tee->bufferInput($tickHandler->getCurrentTick(), $input);
    $world->doTick();
    $sounds = [];
    foreach ($world->doSnap($tee) as $item) {
        if ($item instanceof ObjEventSoundWorldItem) {
            $sounds[] = $item->soundId;
        }
    }
    $world->clearEvents();
    $tickHandler->advanceTick();

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
        $currentTick = $tickHandler->getCurrentTick();
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
    $character->ammoRegenStart                           = $tickHandler->getCurrentTick() - 24;

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

test('custom regeneration intervals and ammo caps use the shared weapon lifecycle', function (int $weapon, int $interval) {
    [$world, $clock, $tee, $character] = createAmmoRegenerationWorld(custom: true);
    $character->giveWeapon($weapon, 0);
    $character->setWeapon($weapon);
    $character->weapons[$weapon]->ammo = 0;
    $character->ammoRegenStart         = -1;
    advanceAmmoRegenerationTick($world, $clock, $tee, input());

    for ($tick = 1; $tick < $interval; $tick++) {
        advanceAmmoRegenerationTick($world, $clock, $tee, input());
        expect($character->weapons[$weapon]->ammo)->toBe(0);
    }
    advanceAmmoRegenerationTick($world, $clock, $tee, input());
    expect($character->weapons[$weapon]->ammo)->toBe(1);

    for ($tick = 0; $tick < 5 * ($interval + 1); $tick++) {
        advanceAmmoRegenerationTick($world, $clock, $tee, input());
        expect($character->weapons[$weapon]->ammo)->toBeLessThanOrEqual(4);
    }
    expect($character->weapons[$weapon]->ammo)->toBe(4);
    expect($character->ammoRegenStart)->toBe(-1);
})->with([
    'gun'     => [GameConstants::WEAPON_GUN, 31],
    'shotgun' => [GameConstants::WEAPON_SHOTGUN, 50],
    'grenade' => [GameConstants::WEAPON_GRENADE, 50],
    'rifle'   => [GameConstants::WEAPON_RIFLE, 60],
]);

test('custom regeneration keeps its window across repeated empty-gun attempts', function (int $pressInterval) {
    [$world, $clock, $tee, $character]                   = createAmmoRegenerationWorld(custom: true);
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 0;
    $fireCounter                                         = -1;
    $shotTicks                                           = [];

    for ($tick = 0; $tick < 150; $tick++) {
        if ($tick % $pressInterval === 0) {
            $fireCounter = ($fireCounter + 2) & PvpCharacterEntity::INPUT_STATE_MASK;
        }
        $currentTick = $clock->getCurrentTick();
        $sounds      = advanceAmmoRegenerationTick($world, $clock, $tee, input(['fire' => $fireCounter]));
        if (in_array(GameConstants::SOUND_GUN_FIRE, $sounds, true)) {
            $shotTicks[] = $currentTick;
        }
    }

    expect(count($shotTicks))->toBeGreaterThanOrEqual(2);
    for ($i = 1; $i < count($shotTicks); $i++) {
        expect($shotTicks[$i] - $shotTicks[$i - 1])
            ->toBeGreaterThanOrEqual(31)
            ->toBeLessThanOrEqual(31 + 12 + $pressInterval);
    }
})->with([1, 6, 8]);

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
