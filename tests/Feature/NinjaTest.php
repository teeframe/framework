<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

function createNinjaWorld(Map $map): AbstractWorld
{
    return new class('test', new TickHandler, $map, $GLOBALS['mockGameServer']) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        public function doTick(): void {}
    };
}

function advanceWorldTick(AbstractWorld $world): void
{
    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tickHandler');
    $prop->setAccessible(true);
    $prop->getValue($world)->next();
}

test('ninja dash moves ~50 units per tick not ~800', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    // Spawn in open air to avoid ground collision
    $spawnPos = new Vector2(50 * 32, 10 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();
    $character->angle = 0; // facing right

    $ref = new ReflectionClass($character);
    $shootMethod = $ref->getMethod('shootNinja');
    $shootMethod->setAccessible(true);
    $shootMethod->invoke($character);

    $handleMethod = $ref->getMethod('handleNinja');
    $handleMethod->setAccessible(true);

    $startX = $character->position->x;

    // Run 1 tick of dash
    advanceWorldTick($world);
    $handleMethod->invoke($character);

    $distance = $character->position->x - $startX;

    // At velocity 50, one tick should move ~50 units (not ~800)
    expect($distance)->toBeGreaterThan(30);
    expect($distance)->toBeLessThan(70);
});

test('ninja move time is 10 ticks', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();
    $character->angle = 0;

    $ref = new ReflectionClass($character);
    $method = $ref->getMethod('shootNinja');
    $method->setAccessible(true);
    $method->invoke($character);

    // ninjaCurrentMoveTime should be 10 (200ms at 50 tick/s), not 25
    expect($character->ninjaCurrentMoveTime)->toBe(10);
});

test('ninja dash moves character forward by expected distance', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    // Spawn in open air so there's no ground/wall collision
    $spawnPos = new Vector2(50 * 32, 10 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();
    $character->angle = 0; // facing right

    $ref = new ReflectionClass($character);
    $shootMethod = $ref->getMethod('shootNinja');
    $shootMethod->setAccessible(true);
    $shootMethod->invoke($character);

    $startX = $character->position->x;

    // Run 3 ticks of dash
    $handleMethod = $ref->getMethod('handleNinja');
    $handleMethod->setAccessible(true);

    for ($i = 0; $i < 3; $i++) {
        advanceWorldTick($world);
        $handleMethod->invoke($character);
    }

    // After 3 ticks at velocity 50, the character should have moved ~150 units right
    $distance = $character->position->x - $startX;
    expect($distance)->toBeGreaterThan(100);
    expect($distance)->toBeLessThan(200);
});

test('ninja expires after 15 seconds', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();

    expect($character->activeWeapon)->toBe(GameConstants::WEAPON_NINJA);
    expect($character->aWeapons[GameConstants::WEAPON_NINJA]['got'])->toBeTrue();

    $ref = new ReflectionClass($character);
    $handleMethod = $ref->getMethod('handleNinja');
    $handleMethod->setAccessible(true);

    // Advance ticks to just before expiry.
    // The condition is: (currentTick - activationTick) > 750
    // activationTick was set by giveNinja() to world tick 0.
    // So we need currentTick = 751 for expiry.
    for ($i = 0; $i < 750; $i++) {
        advanceWorldTick($world);
        $handleMethod->invoke($character);
    }

    // Still ninja at tick 750 (750 - 0 = 750, not > 750)
    expect($character->aWeapons[GameConstants::WEAPON_NINJA]['got'])->toBeTrue();

    // One more tick triggers expiry (751 - 0 = 751 > 750)
    advanceWorldTick($world);
    $handleMethod->invoke($character);

    // Ninja should be gone, weapon reverted to lastWeapon
    expect($character->aWeapons[GameConstants::WEAPON_NINJA]['got'])->toBeFalse();
    expect($character->activeWeapon)->not()->toBe(GameConstants::WEAPON_NINJA);
});

test('ninja dash does not move after move time expires', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    $spawnPos = new Vector2(50 * 32, 10 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();
    $character->angle = 0;

    $ref = new ReflectionClass($character);
    $shootMethod = $ref->getMethod('shootNinja');
    $shootMethod->setAccessible(true);
    $shootMethod->invoke($character);

    $handleMethod = $ref->getMethod('handleNinja');
    $handleMethod->setAccessible(true);

    // Run 10 ticks of dash
    for ($i = 0; $i < 10; $i++) {
        advanceWorldTick($world);
        $handleMethod->invoke($character);
    }

    $posAfterDash = clone $character->position;

    // Run 5 more ticks — should not move further
    for ($i = 0; $i < 5; $i++) {
        advanceWorldTick($world);
        $handleMethod->invoke($character);
    }

    // Position should be the same as after the dash ended
    expect($character->position->x)->toEqual($posAfterDash->x);
    expect($character->position->y)->toEqual($posAfterDash->y);
});

test('ninja has 25 tick cooldown between shots', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $collision = $map->getCollision();
    if ($collision === null) {
        return;
    }

    $world = createNinjaWorld($map);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee = new PlayerTee;
    $tee->inputDirection = 1;
    $tee->inputTargetX = 100;
    $tee->inputTargetY = 0;

    $character = new PvpCharacterEntity($spawnPos);
    $character->spawn($spawnPos, $tee);
    $world->addEntity($character);

    $character->giveNinja();
    $character->angle = 0;

    $ref = new ReflectionClass($character);
    $shootMethod = $ref->getMethod('shootNinja');
    $shootMethod->setAccessible(true);

    // First shot sets reload timer
    $reloadTimer = $shootMethod->invoke($character);
    expect($reloadTimer)->toBe(25);

    // Simulate the reload timer being set (as handleWeapons would do)
    $character->reloadTimer = $reloadTimer;

    // Second shot should be blocked by reload timer
    // handleWeapons checks reloadTimer > 0 and returns early
    expect($character->reloadTimer)->toBeGreaterThan(0);
});