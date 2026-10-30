<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\Chunks\Game\SvKillMsgChunk;

/** @return array{TestWorldWithController, PvpCharacterEntity, PvpCharacterEntity, PlayerTee, PlayerTee} */
function createLaserDamageWorld(string $gameMode = 'DM'): array
{
    $map = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(64, 8, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 64 * 8));
            $this->initCollision();
        }
    };
    $tickHandler = new TickHandler(100);
    $controller  = new PvpGameController($tickHandler);
    $controller->setGameMode($gameMode);
    $world = new TestWorldWithController($tickHandler, $map, $controller);

    $attackerTee = new PlayerTee;
    $victimTee   = new PlayerTee;
    $world->addTee($attackerTee);
    $world->addTee($victimTee);
    $attackerTee->team = GameConstants::TEAM_RED;
    $victimTee->team   = $gameMode === 'DM' ? GameConstants::TEAM_RED : GameConstants::TEAM_BLUE;

    $attacker = new PvpCharacterEntity($world, new Vector2(64, 64));
    $attacker->spawn(new Vector2(64, 64), $attackerTee);
    $attacker->activeWeapon = GameConstants::WEAPON_RIFLE;
    $world->addEntity($attacker);

    $victim = new PvpCharacterEntity($world, new Vector2(192, 64));
    $victim->spawn(new Vector2(192, 64), $victimTee);
    $world->addEntity($victim);

    recordingServer($world)->clear();

    return [$world, $attacker, $victim, $attackerTee, $victimTee];
}

function fireDamageTestLaser(TestWorldWithController $world, PvpCharacterEntity $attacker): void
{
    $tee = $attacker->tee;
    if (! $tee instanceof PlayerTee) {
        throw new LogicException('Laser attacker must have a player tee');
    }

    $world->addEntity(new PvpLaserEntity(
        $world,
        position: clone $attacker->getPosition(),
        direction: new Vector2(1, 0),
        energy: $world->getTuneController()->laserReach / 100.0,
        owner: $tee->teeIndex,
    ));
}

test('laser deals full damage and sends the hit sound only to its owner', function (string $gameMode) {
    [$world, $attacker, $victim, $attackerTee, $victimTee] = createLaserDamageWorld($gameMode);
    $world->getTuneController()->laserDamage               = 700;

    fireDamageTestLaser($world, $attacker);

    expect($victim->health)->toBe(3);
    expect($attacker->health)->toBe(10);
    expect(soundsSentToTee($world, $attackerTee->teeIndex))->toBe([GameConstants::SOUND_HIT]);
    expect(soundsSentToTee($world, $victimTee->teeIndex))->toBe([]);
})->with(['DM', 'TDM', 'CTF']);

test('laser obeys friendly fire protection', function (string $gameMode) {
    [$world, $attacker, $victim, $attackerTee, $victimTee] = createLaserDamageWorld($gameMode);
    $victimTee->team                                       = $attackerTee->team;

    fireDamageTestLaser($world, $attacker);

    expect($victim->health)->toBe(10);
    expect($victim->alive)->toBeTrue();
    expect(soundsSentToTee($world, $attackerTee->teeIndex))->toBe([]);
    expect(soundsSentToTee($world, $victimTee->teeIndex))->toBe([]);
})->with(['TDM', 'CTF']);

test('a lethal laser credits its owner instead of the victim', function () {
    [$world, $attacker, $victim, $attackerTee, $victimTee] = createLaserDamageWorld();
    $victim->health                                        = 2;

    fireDamageTestLaser($world, $attacker);

    expect($victim->alive)->toBeFalse();
    expect($attackerTee->score)->toBe(1);
    expect($victimTee->score)->toBe(0);

    $killMessages = array_values(array_filter(
        recordingServer($world)->sentChunks,
        fn ($chunk) => $chunk instanceof SvKillMsgChunk,
    ));
    expect($killMessages)->toHaveCount(2);
    foreach ($killMessages as $message) {
        expect($message->killer)->toBe($attackerTee->teeIndex);
        expect($message->victim)->toBe($victimTee->teeIndex);
        expect($message->weapon)->toBe(GameConstants::WEAPON_RIFLE);
    }
});

test('a laser without an owner character does not attribute damage to its victim', function () {
    [$world, $attacker, $victim] = createLaserDamageWorld();
    $attacker->die();
    recordingServer($world)->clear();

    fireDamageTestLaser($world, $attacker);

    expect($victim->health)->toBe(10);
    expect($victim->alive)->toBeTrue();
    expect(recordingServer($world)->sentChunks)->toBe([]);
});
