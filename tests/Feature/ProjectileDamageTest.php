<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvKillMsgChunk;

/** @return array{TestWorldWithController, TickHandler, PvpCharacterEntity, PvpCharacterEntity, PlayerTee, PlayerTee} */
function createProjectileDamageWorld(string $mode = 'DM'): array
{
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $controller->setGameMode($mode);
    $world  = new TestWorldWithController($clock, new Map, $controller);
    $owner  = new PlayerTee;
    $target = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($target);
    $owner->team  = GameConstants::TEAM_RED;
    $target->team = $mode === 'DM' ? GameConstants::TEAM_RED : GameConstants::TEAM_BLUE;

    $attacker = new PvpCharacterEntity($world, new Vector2(64, 64));
    $attacker->spawn(new Vector2(64, 64), $owner);
    $world->addEntity($attacker);
    $victim = new PvpCharacterEntity($world, new Vector2(192, 64));
    $victim->spawn(new Vector2(192, 64), $target);
    $world->addEntity($victim);
    recordingServer($world)->clear();

    return [$world, $clock, $attacker, $victim, $owner, $target];
}

function damageTestProjectile(TestWorldWithController $world, int $weapon, int $owner): PvpProjectileEntity
{
    $grenade    = $weapon === GameConstants::WEAPON_GRENADE;
    $projectile = new PvpProjectileEntity(
        $world,
        position: new Vector2($grenade ? 192 : 160, 64),
        direction: new Vector2($grenade ? 0 : 1, 0),
        type: $weapon,
        owner: $owner,
    );
    $projectile->setTuning(1600, 0, $grenade ? 0 : 50);
    $world->addEntity($projectile);

    return $projectile;
}

test('projectiles retain attacker and weapon attribution independently of the owner character', function (int $weapon, string $ownerState, bool $lethal) {
    [$world, $clock, $attacker, $victim, $owner, $target] = createProjectileDamageWorld();
    $projectile                                           = damageTestProjectile($world, $weapon, $owner->teeIndex);
    $attacker->activeWeapon                               = GameConstants::WEAPON_HAMMER;

    if ($ownerState !== 'alive') {
        $attacker->die();
        $world->removeEntity($attacker);
        if ($ownerState === 'respawned') {
            $replacement = new PvpCharacterEntity($world, new Vector2(64, 64));
            $replacement->spawn(new Vector2(64, 64), $owner);
            $replacement->activeWeapon = GameConstants::WEAPON_NINJA;
            $world->addEntity($replacement);
        } elseif ($ownerState === 'disconnected') {
            $world->removeTee($owner);
        }
    }
    $victim->health = $lethal ? 1 : 10;
    recordingServer($world)->clear();
    $clock->next();
    $projectile->doTick();

    $damage = $weapon === GameConstants::WEAPON_GRENADE ? 6 : 1;
    expect($projectile->isToDestroy())->toBeTrue();
    expect($victim->health)->toBe(($lethal ? 1 : 10) - $damage);
    expect($victim->alive)->toBe(! $lethal);
    expect($target->score)->toBe(0);
    expect($owner->score)->toBe($lethal && $ownerState !== 'disconnected' ? 1 : 0);
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe($ownerState === 'disconnected' ? [] : [GameConstants::SOUND_HIT]);
    expect(soundsSentToTee($world, $target->teeIndex))->toBe([]);

    $kills = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvKillMsgChunk));
    expect($kills)->toHaveCount($lethal ? ($ownerState === 'disconnected' ? 1 : 2) : 0);
    foreach ($kills as $kill) {
        expect($kill->killer)->toBe($owner->teeIndex);
        expect($kill->victim)->toBe($target->teeIndex);
        expect($kill->weapon)->toBe($weapon);
    }
})->with([
    'gun'     => GameConstants::WEAPON_GUN,
    'shotgun' => GameConstants::WEAPON_SHOTGUN,
    'grenade' => GameConstants::WEAPON_GRENADE,
])->with(['alive', 'dead', 'respawned', 'disconnected'])->with([false, true]);

test('ownerless projectiles deal damage without blaming the victim', function (int $weapon) {
    [$world, $clock, , $victim, $owner, $target] = createProjectileDamageWorld();
    $projectile                                  = damageTestProjectile($world, $weapon, -1);
    $victim->health                              = 1;
    $clock->next();
    $projectile->doTick();

    expect($victim->alive)->toBeFalse();
    expect($target->score)->toBe(0);
    expect($owner->score)->toBe(0);
    expect(soundsSentToTee($world, $target->teeIndex))->toBe([]);
    $kills = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvKillMsgChunk));
    expect($kills)->toHaveCount(2);
    foreach ($kills as $kill) {
        expect($kill->killer)->toBe(-1);
        expect($kill->weapon)->toBe($weapon);
    }
})->with([GameConstants::WEAPON_GUN, GameConstants::WEAPON_SHOTGUN, GameConstants::WEAPON_GRENADE]);

test('projectiles still obey team damage protection after their owner dies', function (int $weapon, string $mode) {
    [$world, $clock, $attacker, $victim, $owner, $target] = createProjectileDamageWorld($mode);
    $projectile                                           = damageTestProjectile($world, $weapon, $owner->teeIndex);
    $target->team                                         = $owner->team;
    $attacker->die();
    $world->removeEntity($attacker);
    recordingServer($world)->clear();
    $clock->next();
    $projectile->doTick();

    expect($victim->health)->toBe(10);
    expect($victim->alive)->toBeTrue();
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe([]);
    expect($victim->vel->length())->toBeGreaterThan(0);
})->with([GameConstants::WEAPON_GUN, GameConstants::WEAPON_SHOTGUN, GameConstants::WEAPON_GRENADE])->with(['TDM', 'CTF']);

test('a grenade recognizes its respawned owner for self damage', function (bool $lethal) {
    [$world, $clock, $attacker, $victim, $owner] = createProjectileDamageWorld();
    $victim->setPosition(new Vector2(1024, 64));
    $projectile = damageTestProjectile($world, GameConstants::WEAPON_GRENADE, $owner->teeIndex);
    $attacker->die();
    $world->removeEntity($attacker);
    $replacement = new PvpCharacterEntity($world, new Vector2(192, 64));
    $replacement->spawn(new Vector2(192, 64), $owner);
    $replacement->activeWeapon = GameConstants::WEAPON_HAMMER;
    $replacement->health       = $lethal ? 3 : 10;
    $world->addEntity($replacement);
    recordingServer($world)->clear();
    $clock->next();
    $projectile->doTick();

    expect($replacement->health)->toBe($lethal ? 0 : 7);
    expect($replacement->alive)->toBe(! $lethal);
    expect($owner->score)->toBe($lethal ? -1 : 0);
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe([]);
    $kills = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvKillMsgChunk));
    expect($kills)->toHaveCount($lethal ? 2 : 0);
    foreach ($kills as $kill) {
        expect($kill->killer)->toBe($owner->teeIndex);
        expect($kill->victim)->toBe($owner->teeIndex);
        expect($kill->weapon)->toBe(GameConstants::WEAPON_GRENADE);
    }
})->with([false, true]);
