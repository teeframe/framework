<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

/** @return array{TestWorldWithController, PvpGameController, PlayerTee, PlayerTee, PvpCharacterEntity, PvpCharacterEntity} */
function createPvpScoringWorld(string $mode = 'TDM', int $scoreLimit = 0): array
{
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock, scoreLimit: $scoreLimit);
    $controller->setGameMode($mode);
    $map   = $mode === 'CTF' ? new Map(__DIR__.'/../ctf1.map') : new Map;
    $world = new TestWorldWithController($clock, $map, $controller);
    $red   = new PlayerTee;
    $blue  = new PlayerTee;
    $world->addTee($red);
    $world->addTee($blue);
    $red->team    = GameConstants::TEAM_RED;
    $blue->team   = $mode === 'DM' ? GameConstants::TEAM_RED : GameConstants::TEAM_BLUE;
    $redCharacter = new PvpCharacterEntity($world, new Vector2(64, 64));
    $redCharacter->spawn(new Vector2(64, 64), $red);
    $world->addEntity($redCharacter);
    $blueCharacter = new PvpCharacterEntity($world, new Vector2(192, 64));
    $blueCharacter->spawn(new Vector2(192, 64), $blue);
    $world->addEntity($blueCharacter);

    return [$world, $controller, $red, $blue, $redCharacter, $blueCharacter];
}

/** @return array{int, int} */
function pvpSnapshotTeamScores(PvpGameController $controller): array
{
    $data = $controller->doSnap(new PlayerTee)[1];
    assert($data instanceof ObjGameDataItem);

    return [$data->teamScoreRed, $data->teamScoreBlue];
}

test('TDM keeps team points when the scorer switches teams spectates or disconnects', function (string $transition) {
    [$world, $controller, $red, , , $victim] = createPvpScoringWorld();
    $victim->die($red->teeIndex, GameConstants::WEAPON_GUN);
    expect($red->score)->toBe(1);
    expect(pvpSnapshotTeamScores($controller))->toBe([1, 0]);

    if ($transition === 'disconnect') {
        $world->removeTee($red);
    } else {
        $red->setTeam($transition === 'spectate' ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_BLUE);
        expect($red->score)->toBe(1);
    }

    expect(pvpSnapshotTeamScores($controller))->toBe([1, 0]);
})->with(['switch', 'spectate', 'disconnect']);

test('TDM penalizes suicides and teamkills but ignores game deaths', function (string $cause, int $expected) {
    [, $controller, $red, $blue, $redCharacter, $blueCharacter] = createPvpScoringWorld();
    if ($cause === 'teamkill') {
        $blue->team = $red->team;
        $blueCharacter->die($red->teeIndex, GameConstants::WEAPON_GRENADE);
    } else {
        $redCharacter->die($red->teeIndex, match ($cause) {
            'game'  => GameConstants::WEAPON_GAME,
            'world' => GameConstants::WEAPON_WORLD,
            default => GameConstants::WEAPON_SELF,
        });
    }

    expect($red->score)->toBe($expected);
    expect($blue->score)->toBe(0);
    expect(pvpSnapshotTeamScores($controller))->toBe([$expected, 0]);
})->with(['suicide' => ['self', -1], 'death tile' => ['world', -1], 'teamkill' => ['teamkill', -1], 'team transition' => ['game', 0]]);

test('CTF kills and suicides affect personal scores only', function () {
    [, $controller, $red, $blue, $redCharacter, $blueCharacter] = createPvpScoringWorld('CTF');
    $blueCharacter->die($red->teeIndex, GameConstants::WEAPON_GUN);
    expect($red->score)->toBe(1);
    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);

    $redCharacter->die($red->teeIndex, GameConstants::WEAPON_SELF);
    expect($red->score)->toBe(0);
    expect($blue->score)->toBe(0);
    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);
});

test('CTF flag rewards keep team and personal points separate', function (int $team) {
    [, $controller, $red, $blue, $redCharacter, $blueCharacter] = createPvpScoringWorld('CTF');
    $carrier                                                    = $team === GameConstants::TEAM_RED ? $redCharacter : $blueCharacter;
    $tee                                                        = $team === GameConstants::TEAM_RED ? $red : $blue;
    $enemyFlag                                                  = $controller->getFlag($team ^ 1);
    $ownFlag                                                    = $controller->getFlag($team);
    assert($enemyFlag instanceof FlagEntity && $ownFlag instanceof FlagEntity);

    $carrier->setPosition(clone $enemyFlag->getPosition());
    $controller->doTick();
    $scores        = [0, 0];
    $scores[$team] = 1;
    expect($enemyFlag->carryingCharacter)->toBe($carrier);
    expect($tee->score)->toBe(1);
    expect(pvpSnapshotTeamScores($controller))->toBe($scores);

    $carrier->setPosition(clone $ownFlag->getPosition());
    $controller->doTick();
    $scores[$team] = 101;
    expect($tee->score)->toBe(6);
    expect(pvpSnapshotTeamScores($controller))->toBe($scores);
    expect($enemyFlag->atStand)->toBeTrue();
    expect($ownFlag->atStand)->toBeTrue();
})->with([GameConstants::TEAM_RED, GameConstants::TEAM_BLUE]);

test('picking up a dropped enemy flag grants only a personal point', function () {
    [, $controller, $red, , $redCharacter] = createPvpScoringWorld('CTF');
    $flag                                  = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($flag instanceof FlagEntity);
    $flag->atStand = false;
    $flag->setPosition(clone $redCharacter->getPosition());

    $controller->doTick();

    expect($flag->carryingCharacter)->toBe($redCharacter);
    expect($red->score)->toBe(1);
    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);
});

test('returning a dropped friendly flag grants only a personal point', function () {
    [, $controller, $red, , $redCharacter] = createPvpScoringWorld('CTF');
    $flag                                  = $controller->getFlag(GameConstants::TEAM_RED);
    assert($flag instanceof FlagEntity);
    $flag->atStand = false;
    $flag->setPosition(clone $redCharacter->getPosition());

    $controller->doTick();

    expect($flag->atStand)->toBeTrue();
    expect($red->score)->toBe(1);
    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);
});

test('killing a CTF flag carrier grants two personal points and no team points', function () {
    [, $controller, $red, $blue, , $blueCharacter] = createPvpScoringWorld('CTF');
    $flag                                          = $controller->getFlag(GameConstants::TEAM_RED);
    assert($flag instanceof FlagEntity);
    $flag->atStand           = false;
    $flag->carryingCharacter = $blueCharacter;

    $blueCharacter->die($red->teeIndex, GameConstants::WEAPON_GUN);

    expect($red->score)->toBe(2);
    expect($blue->score)->toBe(0);
    expect($flag->carryingCharacter)->toBeNull();
    expect($flag->atStand)->toBeFalse();
    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);
});

test('round and map resets clear team points without affecting another world', function (bool $mapReset) {
    [, $controller, $red, , , $victim]                = createPvpScoringWorld();
    [, $otherController, $otherRed, , , $otherVictim] = createPvpScoringWorld();
    $victim->die($red->teeIndex, GameConstants::WEAPON_GUN);
    $otherVictim->die($otherRed->teeIndex, GameConstants::WEAPON_GUN);

    if ($mapReset) {
        $controller->resetMap();
    } else {
        $controller->startRound();
    }

    expect(pvpSnapshotTeamScores($controller))->toBe([0, 0]);
    expect(pvpSnapshotTeamScores($otherController))->toBe([1, 0]);
})->with(['round' => false, 'map' => true]);

test('TDM win checks use earned team points instead of personal totals', function () {
    [, $controller, $red, $blue, , $victim] = createPvpScoringWorld(scoreLimit: 1);
    $red->score                             = 100;
    $blue->score                            = 200;
    $controller->doWincheck();
    expect($controller->isGameOver())->toBeFalse();

    $victim->die($red->teeIndex, GameConstants::WEAPON_GUN);
    $red->score = 0;
    $controller->doWincheck();
    expect($controller->isGameOver())->toBeTrue();
    expect(pvpSnapshotTeamScores($controller))->toBe([1, 0]);
});

test('CTF sudden death requires a capture lead rather than another stand grab', function () {
    [, $controller, $red, $blue, $redCharacter, $blueCharacter] = createPvpScoringWorld('CTF', scoreLimit: 1);
    $redFlag                                                    = $controller->getFlag(GameConstants::TEAM_RED);
    $blueFlag                                                   = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($redFlag instanceof FlagEntity && $blueFlag instanceof FlagEntity);
    $redCharacter->setPosition(clone $blueFlag->getPosition());
    $blueCharacter->setPosition(clone $redFlag->getPosition());
    $controller->doTick();
    expect(pvpSnapshotTeamScores($controller))->toBe([1, 1]);
    $info = $controller->doSnap($red)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_SUDDENDEATH)->not->toBe(0);

    $blueFlag->reset();
    $controller->doTick();
    expect(pvpSnapshotTeamScores($controller))->toBe([2, 1]);
    expect($controller->isGameOver())->toBeFalse();

    $redFlag->reset();
    $blueCharacter->setPosition(new Vector2(192, 64));
    $redCharacter->setPosition(clone $redFlag->getPosition());
    $controller->doTick();
    expect(pvpSnapshotTeamScores($controller))->toBe([102, 1]);
    expect($controller->isGameOver())->toBeTrue();
    expect($red->score)->toBe(7);
    expect($blue->score)->toBe(1);
});
