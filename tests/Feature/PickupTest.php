<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\PickupSpawner;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

/** @return array{TestWorld, PvpCharacterEntity, PvpCharacterEntity} */
function createPickupSelectionWorld(bool $nearerFirst = false): array
{
    $world   = new TestWorld(new TickHandler(100), new Map);
    $farther = new PvpCharacterEntity($world, new Vector2(96, 64));
    $nearer  = new PvpCharacterEntity($world, new Vector2(68, 64));
    $farther->spawn(new Vector2(96, 64));
    $nearer->spawn(new Vector2(68, 64));
    $farther->health = 5;
    $nearer->health  = 5;
    foreach ($nearerFirst ? [$nearer, $farther] : [$farther, $nearer] as $character) {
        $world->addEntity($character);
    }

    return [$world, $farther, $nearer];
}

/** @return array{TestWorld, TickHandler, PvpCharacterEntity} */
function createPickupAvailabilityWorld(int $tick = 100): array
{
    $clock     = new TickHandler($tick);
    $world     = new TestWorld($clock, new Map);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64));
    $character->health = 5;
    $world->addEntity($character);

    return [$world, $clock, $character];
}

test('a pickup configured never to respawn is consumed until the round resets', function (int $type) {
    [$world, $clock, $character] = createPickupAvailabilityWorld();
    $pickup                      = new PickupEntity($world, new Vector2(64, 64), $type, GameConstants::WEAPON_SHOTGUN);
    $world->addEntity($pickup);
    $viewer = new PlayerTee;

    $pickup->doTick();
    expect($pickup->doSnap($viewer))->toBeEmpty();
    expect($pickup->isToDestroy())->toBeFalse();
    if ($type === GameConstants::POWERUP_WEAPON) {
        $character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo = 0;
    }
    for ($i = 0; $i < 100; $i++) {
        $clock->next();
        $pickup->doTick();
    }
    expect($pickup->doSnap($viewer))->toBeEmpty();
    expect($character->health)->toBe($type === GameConstants::POWERUP_HEALTH ? 6 : 5);
    expect($character->armor)->toBe($type === GameConstants::POWERUP_ARMOR ? 1 : 0);
    if ($type === GameConstants::POWERUP_WEAPON) {
        expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo)->toBe(0);
    }
    $sounds = array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjEventSoundWorldItem);
    expect($sounds)->toHaveCount(1);

    $world->resetGame();
    $world->doTick();
    expect($world->getEntities())->toBe([$pickup]);
    expect($pickup->doSnap($viewer))->toHaveCount(1);
})->with([GameConstants::POWERUP_HEALTH, GameConstants::POWERUP_ARMOR, GameConstants::POWERUP_WEAPON, GameConstants::POWERUP_NINJA]);

test('always available pickups stay collectible independently of their respawn time', function (int $respawnTime, int $type) {
    [$world, $clock, $character] = createPickupAvailabilityWorld();
    $pickup                      = new PickupEntity($world, new Vector2(64, 64), $type, GameConstants::WEAPON_SHOTGUN, respawnTime: $respawnTime, alwaysAvailable: true);
    $viewer                      = new PlayerTee;

    $pickup->doTick();
    expect($pickup->doSnap($viewer))->toHaveCount(1);
    if ($type === GameConstants::POWERUP_WEAPON) {
        $character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo = 0;
    }
    $clock->next();
    $pickup->doTick();

    expect($pickup->doSnap($viewer))->toHaveCount(1);
    expect($character->health)->toBe($type === GameConstants::POWERUP_HEALTH ? 7 : 5);
    expect($character->armor)->toBe($type === GameConstants::POWERUP_ARMOR ? 2 : 0);
    if ($type === GameConstants::POWERUP_WEAPON) {
        expect($character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo)->toBe(10);
    }
    $sounds = array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjEventSoundWorldItem);
    expect($sounds)->toHaveCount(2);
})->with([-1, 0, 10])->with([GameConstants::POWERUP_HEALTH, GameConstants::POWERUP_ARMOR, GameConstants::POWERUP_WEAPON, GameConstants::POWERUP_NINJA]);

test('failed pickup attempts do not consume pickups configured never to respawn', function (int $type) {
    [$world, , $character] = createPickupAvailabilityWorld();
    $character->health     = 10;
    $character->armor      = 10;
    $character->giveWeapon(GameConstants::WEAPON_SHOTGUN, 10);
    $pickup = new PickupEntity($world, new Vector2(64, 64), $type, GameConstants::WEAPON_SHOTGUN);
    $viewer = new PlayerTee;

    $pickup->doTick();
    expect($pickup->doSnap($viewer))->toHaveCount(1);
    expect(array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjEventSoundWorldItem))->toBeEmpty();

    $character->health                                       = 5;
    $character->armor                                        = 0;
    $character->weapons[GameConstants::WEAPON_SHOTGUN]->ammo = 0;
    $pickup->doTick();
    expect($pickup->doSnap($viewer))->toBeEmpty();
})->with([GameConstants::POWERUP_HEALTH, GameConstants::POWERUP_ARMOR, GameConstants::POWERUP_WEAPON]);

test('timed pickups become collectible only after their respawn deadline', function (int $respawnTime, int $tick) {
    [$world, $clock, $character] = createPickupAvailabilityWorld($tick);
    $pickup                      = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, respawnTime: $respawnTime);
    $viewer                      = new PlayerTee;

    $pickup->doTick();
    $pickup->doTick();
    expect($character->health)->toBe(6);
    expect($pickup->doSnap($viewer))->toBeEmpty();
    for ($i = 0; $i < $respawnTime; $i++) {
        $clock->next();
        $pickup->doTick();
    }
    expect($character->health)->toBe(6);
    expect($pickup->doSnap($viewer))->toBeEmpty();
    $clock->next();
    expect($pickup->doSnap($viewer))->toHaveCount(1);
    $pickup->doTick();
    expect($character->health)->toBe(7);
})->with([0, 3])->with([0, 100]);

test('initial spawn delays apply to both consumable and always available pickups', function (bool $alwaysAvailable) {
    [$world, $clock, $character] = createPickupAvailabilityWorld();
    $pickup                      = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, spawnDelay: 2, alwaysAvailable: $alwaysAvailable);
    $viewer                      = new PlayerTee;

    for ($i = 0; $i <= 2; $i++) {
        $pickup->doTick();
        expect($character->health)->toBe(5);
        expect($pickup->doSnap($viewer))->toBeEmpty();
        $clock->next();
    }
    expect($pickup->doSnap($viewer))->toHaveCount(1);
    $pickup->doTick();
    expect($character->health)->toBe(6);
    expect($pickup->doSnap($viewer))->toHaveCount($alwaysAvailable ? 1 : 0);

    $pickup->reset();
    expect($pickup->doSnap($viewer))->toBeEmpty();
    for ($i = 0; $i < 3; $i++) {
        $clock->next();
    }
    expect($pickup->doSnap($viewer))->toHaveCount(1);
})->with([false, true]);

test('paused time preserves pickup delays without reviving permanently consumed pickups', function (int $respawnTime) {
    [$world, $clock, $character] = createPickupAvailabilityWorld();
    $pickup                      = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, respawnTime: $respawnTime);
    $pickup->doTick();
    for ($i = 0; $i < 10; $i++) {
        $clock->next();
        $pickup->tickPaused();
        expect($pickup->doSnap(new PlayerTee))->toBeEmpty();
    }
    for ($i = 0; $i < 4; $i++) {
        $clock->next();
        $pickup->doTick();
    }
    expect($character->health)->toBe($respawnTime < 0 ? 6 : 7);
})->with([-1, 3]);

test('map pickups configure availability independently for each entity type', function (int $entityType, bool $alwaysAvailable) {
    [$world, $clock, $character] = createPickupAvailabilityWorld();
    $character->setPosition(new Vector2(16, 16));
    $layer = new GameLayer(1, 1, pack('C4', $entityType, 0, 0, 0));
    PickupSpawner::spawn($world, $layer, respawnTimes: [$entityType => -1], alwaysAvailable: [$entityType => $alwaysAvailable]);
    $pickup = $world->getEntities()[1];
    assert($pickup instanceof PickupEntity);

    $pickup->doTick();
    expect($pickup->doSnap(new PlayerTee))->toHaveCount($alwaysAvailable ? 1 : 0);
    $clock->next();
    $pickup->doTick();
    expect($pickup->doSnap(new PlayerTee))->toHaveCount($alwaysAvailable ? 1 : 0);
    $sounds   = array_filter($world->doSnap(new PlayerTee), fn ($item) => $item instanceof ObjEventSoundWorldItem);
    $isWeapon = in_array($entityType, [GameLayer::ENTITY_WEAPON_SHOTGUN, GameLayer::ENTITY_WEAPON_GRENADE, GameLayer::ENTITY_WEAPON_RIFLE], true);
    expect($sounds)->toHaveCount($alwaysAvailable && ! $isWeapon ? 2 : 1);
})->with([GameLayer::ENTITY_HEALTH_1, GameLayer::ENTITY_ARMOR_1, GameLayer::ENTITY_WEAPON_SHOTGUN, GameLayer::ENTITY_WEAPON_GRENADE, GameLayer::ENTITY_WEAPON_RIFLE, GameLayer::ENTITY_POWERUP_NINJA])->with([false, true]);

test('pickups go to the nearest character regardless of insertion order', function (int $type, bool $nearerFirst) {
    [$world, $farther, $nearer] = createPickupSelectionWorld($nearerFirst);
    $pickup                     = new PickupEntity($world, new Vector2(64, 64), $type, GameConstants::WEAPON_SHOTGUN, respawnTime: 50);

    $pickup->doTick();
    $pickup->doTick();

    expect($nearer->health)->toBe($type === GameConstants::POWERUP_HEALTH ? 6 : 5);
    expect($farther->health)->toBe(5);
    expect($nearer->armor)->toBe($type === GameConstants::POWERUP_ARMOR ? 1 : 0);
    expect($farther->armor)->toBe(0);
    expect($nearer->weapons[GameConstants::WEAPON_SHOTGUN]->got)->toBe($type === GameConstants::POWERUP_WEAPON);
    expect($farther->weapons[GameConstants::WEAPON_SHOTGUN]->got)->toBeFalse();
    expect($pickup->doSnap(new PlayerTee))->toBeEmpty();
})->with([GameConstants::POWERUP_HEALTH, GameConstants::POWERUP_ARMOR, GameConstants::POWERUP_WEAPON])->with([false, true]);

test('dead characters do not prevent a living character from taking a pickup', function () {
    [$world, $farther, $nearer] = createPickupSelectionWorld(nearerFirst: true);
    $nearer->alive              = false;
    $pickup                     = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, respawnTime: 50);

    $pickup->doTick();

    expect($farther->health)->toBe(6);
    expect($nearer->health)->toBe(5);
});

test('a pickup does not fall through to a farther character when the nearest cannot use it', function () {
    [$world, $farther, $nearer] = createPickupSelectionWorld();
    $nearer->health             = 10;
    $pickup                     = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, respawnTime: 50);

    $pickup->doTick();

    expect($farther->health)->toBe(5);
    expect($nearer->health)->toBe(10);
    expect($pickup->doSnap(new PlayerTee))->toHaveCount(1);
});

test('pickups remain available when no living character is in reach', function () {
    [$world, $farther, $nearer] = createPickupSelectionWorld();
    $farther->setPosition(new Vector2(200, 64));
    $nearer->setPosition(new Vector2(300, 64));
    $pickup = new PickupEntity($world, new Vector2(64, 64), GameConstants::POWERUP_HEALTH, respawnTime: 50);

    $pickup->doTick();

    expect($farther->health)->toBe(5);
    expect($nearer->health)->toBe(5);
    expect($pickup->doSnap(new PlayerTee))->toHaveCount(1);
});
