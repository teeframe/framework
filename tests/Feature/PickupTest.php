<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;

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
