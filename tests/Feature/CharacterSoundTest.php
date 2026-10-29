<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

/** @return array{AbstractWorld, PvpCharacterEntity, PlayerTee, PlayerTee, PlayerTee} */
function createCharacterSoundWorld(int $wallTile = GameLayer::TILE_SOLID): array
{
    resetMockServer();

    // A floor at y=128 and a wall at x=192 make hook/jump events deterministic.
    $tiles = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 12; $x++) {
            $tile = $y >= 4 ? GameLayer::TILE_SOLID : ($x === 6 ? $wallTile : GameLayer::TILE_AIR);
            $tiles .= pack('C4', $tile, 0, 0, 0);
        }
    }
    $map = new class(new GameLayer(12, 8, $tiles)) extends Map
    {
        public function __construct(GameLayer $gameLayer)
        {
            $this->gameLayer = $gameLayer;
            $this->initCollision();
        }
    };
    $world = createWorld($map);
    $owner = new PlayerTee;
    $other = new PlayerTee;
    $spectator = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($other);
    $world->addTee($spectator);
    $spectator->team = GameConstants::TEAM_SPECTATORS;
    $spectator->viewPosition = new Vector2(128, 110);

    $character = new PvpCharacterEntity($world, new Vector2(128, 110));
    $character->spawn(new Vector2(128, 110), $owner);
    $world->addEntity($character);
    $otherCharacter = new PvpCharacterEntity($world, new Vector2(64, 110));
    $otherCharacter->spawn(new Vector2(64, 110), $other);
    $world->addEntity($otherCharacter);

    return [$world, $character, $owner, $other, $spectator];
}

/** @return ObjEventSoundWorldItem[] */
function characterSoundSnapshot(AbstractWorld $world, PlayerTee $tee): array
{
    return array_values(array_filter(
        $world->doSnap($tee),
        fn ($item) => $item instanceof ObjEventSoundWorldItem,
    ));
}

test('predicted character sounds reach other tees and spectators but not their owner', function (
    int $wallTile,
    bool $jump,
    int $coreEvent,
    int $soundId,
) {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld($wallTile);

    feedInput($character, input(['targetX' => 100, 'targetY' => 0, 'hook' => ! $jump, 'jump' => $jump]));

    expect($character->triggeredEvents & $coreEvent)->toBe($coreEvent);
    // Check the excluded client first: tee index zero must not be treated as "no exclusion".
    expect($owner->teeIndex)->toBe(0);
    expect(characterSoundSnapshot($world, $owner))->toBe([]);
    foreach ([$other, $spectator] as $tee) {
        $sounds = characterSoundSnapshot($world, $tee);
        expect($sounds)->toHaveCount(1);
        expect($sounds[0]->getInts())->toBe([
            (int) round($character->getPosition()->x),
            (int) round($character->getPosition()->y),
            $soundId,
        ]);
    }

    $world->clearEvents();
    feedInput($character, input(['targetX' => 100, 'targetY' => 0, 'hook' => ! $jump, 'jump' => $jump]));
    expect(characterSoundSnapshot($world, $other))->toBe([]);
})->with([
    'hook attaches to ground' => [GameLayer::TILE_SOLID, false, GameConstants::COREEVENT_HOOK_ATTACH_GROUND, GameConstants::SOUND_HOOK_ATTACH_GROUND],
    'hook hits no-hook tile' => [GameLayer::TILE_NOHOOK, false, GameConstants::COREEVENT_HOOK_HIT_NOHOOK, GameConstants::SOUND_HOOK_NOATTACH],
    'ground jump' => [GameLayer::TILE_SOLID, true, GameConstants::COREEVENT_GROUND_JUMP, GameConstants::SOUND_PLAYER_JUMP],
]);

test('hook attaching to a player sends sound to everyone including the hook owner', function () {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    assert($other->character instanceof PvpCharacterEntity);
    $other->character->spawn(new Vector2(176, 110), $other);

    feedInput($character, input(['targetX' => 100, 'targetY' => 0, 'hook' => true]));

    expect($character->triggeredEvents & GameConstants::COREEVENT_HOOK_ATTACH_PLAYER)
        ->toBe(GameConstants::COREEVENT_HOOK_ATTACH_PLAYER);
    foreach ([$other, $owner, $spectator] as $tee) {
        $sounds = characterSoundSnapshot($world, $tee);
        expect($sounds)->toHaveCount(1);
        expect($sounds[0]->soundId)->toBe(GameConstants::SOUND_HOOK_ATTACH_PLAYER);
    }

    $world->clearEvents();
    feedInput($character, input(['targetX' => 100, 'targetY' => 0, 'hook' => true]));
    expect(characterSoundSnapshot($world, $other))->toBe([]);
});

test('event recipient exclusions are per event and cleared before snapshot ids are reused', function (int $excludedTeeIndex) {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    $world->addEvent(new ObjEventSoundWorldItem(128, 110, GameConstants::SOUND_HOOK_ATTACH_GROUND), excludedTeeIndex: $excludedTeeIndex);
    $world->addEvent(new ObjEventSoundWorldItem(128, 110, GameConstants::SOUND_HOOK_ATTACH_PLAYER));

    foreach ([$owner, $other, $spectator] as $tee) {
        $soundIds = array_map(fn (ObjEventSoundWorldItem $sound) => $sound->soundId, characterSoundSnapshot($world, $tee));
        expect($soundIds)->toBe($tee->teeIndex === $excludedTeeIndex
            ? [GameConstants::SOUND_HOOK_ATTACH_PLAYER]
            : [GameConstants::SOUND_HOOK_ATTACH_GROUND, GameConstants::SOUND_HOOK_ATTACH_PLAYER]);
    }

    $world->clearEvents();
    $world->addEvent(new ObjEventSoundWorldItem(128, 110, GameConstants::SOUND_HOOK_ATTACH_PLAYER));
    foreach ([$owner, $other, $spectator] as $tee) {
        $sounds = characterSoundSnapshot($world, $tee);
        expect($sounds)->toHaveCount(1);
        expect($sounds[0]->soundId)->toBe(GameConstants::SOUND_HOOK_ATTACH_PLAYER);
    }
})->with([0, 1, 2]);

test('weapon switching sends the 0.6 switch sound to the owner other players and spectators', function (
    int $wantedWeapon,
    int $nextWeapon,
    int $prevWeapon,
) {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    feedInput($character, input());
    feedInput($character, input());
    $world->clearEvents();
    $soundPosition = clone $character->getPosition();
    $switchInput = input([
        'wantedWeapon' => $wantedWeapon,
        'nextWeapon' => $nextWeapon,
        'prevWeapon' => $prevWeapon,
    ]);

    feedInput($character, $switchInput);

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    foreach ([$owner, $other, $spectator] as $tee) {
        $sounds = characterSoundSnapshot($world, $tee);
        expect($sounds)->toHaveCount(1);
        // Teeworlds 0.6's weapon_switch is sound ID 10, emitted before movement.
        expect($sounds[0]->getInts())->toBe([
            (int) round($soundPosition->x),
            (int) round($soundPosition->y),
            10,
        ]);
    }

    $world->clearEvents();
    feedInput($character, $switchInput);
    foreach ([$owner, $other, $spectator] as $tee) {
        expect(characterSoundSnapshot($world, $tee))->toBe([]);
    }
})->with([
    'direct selection' => [GameConstants::WEAPON_HAMMER + 1, 0, 0],
    'next weapon' => [0, 1, 0],
    'previous weapon' => [0, 0, 1],
]);

test('selecting the current or an unavailable weapon does not emit a switch sound', function (int $wantedWeapon) {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    feedInput($character, input());
    feedInput($character, input());
    $world->clearEvents();

    feedInput($character, input(['wantedWeapon' => $wantedWeapon]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(-1);
    foreach ([$owner, $other, $spectator] as $tee) {
        expect(characterSoundSnapshot($world, $tee))->toBe([]);
    }
})->with([
    'current weapon' => [GameConstants::WEAPON_GUN + 1],
    'unowned weapon' => [GameConstants::WEAPON_SHOTGUN + 1],
    'out-of-range weapon' => [GameConstants::NUM_WEAPONS + 1],
]);

test('a reload-queued weapon switch emits sound only when the switch executes', function () {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    feedInput($character, input());
    feedInput($character, input());
    $world->clearEvents();
    $character->reloadTimer = 2;

    feedInput($character, input(['wantedWeapon' => GameConstants::WEAPON_HAMMER + 1]));

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    expect($character->queuedWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    foreach ([$owner, $other, $spectator] as $tee) {
        expect(characterSoundSnapshot($world, $tee))->toBe([]);
    }

    feedInput($character, input());
    expect($character->reloadTimer)->toBe(0);
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_GUN);
    foreach ([$owner, $other, $spectator] as $tee) {
        expect(characterSoundSnapshot($world, $tee))->toBe([]);
    }

    feedInput($character, input());
    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_HAMMER);
    expect($character->queuedWeapon)->toBe(-1);
    foreach ([$owner, $other, $spectator] as $tee) {
        $sounds = characterSoundSnapshot($world, $tee);
        expect($sounds)->toHaveCount(1);
        expect($sounds[0]->soundId)->toBe(GameConstants::SOUND_WEAPON_SWITCH);
    }
});

test('air jumps and hook launches do not create server sound events in Teeworlds 0.6', function (
    bool $jump,
    bool $hook,
    int $coreEvent,
) {
    [$world, $character, $owner, $other, $spectator] = createCharacterSoundWorld();
    $character->spawn(new Vector2(128, 80), $owner);

    feedInput($character, input(['jump' => $jump, 'hook' => $hook]));

    expect($character->triggeredEvents & $coreEvent)->toBe($coreEvent);
    foreach ([$owner, $other, $spectator] as $tee) {
        expect(characterSoundSnapshot($world, $tee))->toBe([]);
    }
})->with([
    'air jump' => [true, false, GameConstants::COREEVENT_AIR_JUMP],
    'hook launch without collision' => [false, true, GameConstants::COREEVENT_HOOK_LAUNCH],
]);
