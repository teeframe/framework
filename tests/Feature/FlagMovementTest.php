<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjFlagItem;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Server\Config\Config;

/** @return array{TestPvpWorldWithController, TickHandler, PlayerTee, PvpCharacterEntity} */
function flagMovementFixture(int $team = GameConstants::TEAM_BLUE, int $scoreLimit = 0): array
{
    $clock = new TickHandler(100);
    $map   = new class extends Map
    {
        public function __construct()
        {
            $tiles                    = str_repeat("\0", 32 * 16 * 4);
            $tiles[4 * (4 * 32 + 4)]  = chr(GameLayer::ENTITY_FLAGSTAND_RED);
            $tiles[4 * (4 * 32 + 24)] = chr(GameLayer::ENTITY_FLAGSTAND_BLUE);
            $tiles[4 * (8 * 32 + 12)] = chr(GameLayer::ENTITY_SPAWN);
            $this->gameLayer          = new GameLayer(32, 16, $tiles);
            $this->collision          = $this->createCollision($this->gameLayer);
        }
    };
    $controller = new PvpGameController($clock);
    $world      = new TestPvpWorldWithController($clock, $map, $controller, config: new Config([
        'sv_world_game_type'       => 'ctf',
        'sv_world_pvp_score_limit' => $scoreLimit,
    ]));
    $world->getTuneController()->gravity = 0;
    $tee                                 = new PlayerTee;
    $world->addTee($tee);
    $tee->team = $team;
    $character = new PvpCharacterEntity($world, new Vector2(400, 144));
    $character->spawn(new Vector2(400, 144), $tee);
    $world->addEntity($character);
    recordingServer($world)->clear();

    return [$world, $clock, $tee, $character];
}

test('carried flags and their snapshots use the position after character movement', function () {
    [$world, $clock, $tee, $character] = flagMovementFixture();
    $flag                              = $world->getGameController()->getFlag(GameConstants::TEAM_RED);
    assert($flag !== null);
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());
    $character->vel->x = 10;
    $character->onPredictedInput(input(['direction' => 1]));

    for ($i = 0; $i < 6; $i++) {
        $world->doTick();

        expect($character->getPosition()->x)->toBe(410.0 + 10 * $i);
        expect($flag->getPosition())->toEqual($character->getPosition());
        expect($flag->getPosition())->not->toBe($character->getPosition());
        $snaps = array_values(array_filter($world->doSnap($tee), fn ($item) => $item instanceof ObjFlagItem && $item->team === GameConstants::TEAM_RED));
        expect($snaps)->toHaveCount(1);
        expect(array_slice($snaps[0]->getInts(), 0, 2))->toBe([(int) $character->getPosition()->x, 144]);
        $clock->advanceTick();
    }
});

test('CTF capture range is checked after movement rather than before it', function (int $distance, int $direction, bool $captured) {
    [$world, , $tee, $character] = flagMovementFixture();
    $controller                  = $world->getGameController();
    $flag                        = $controller->getFlag(GameConstants::TEAM_RED);
    $ownFlag                     = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($flag !== null && $ownFlag !== null);
    $character->setPosition(new Vector2($ownFlag->standPos->x - $distance, $ownFlag->standPos->y));
    $character->vel->x = $direction * 10;
    $character->onPredictedInput(input(['direction' => $direction]));
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());

    $world->doTick();

    expect($character->getPosition()->distance($ownFlag->standPos))->toBe($captured ? 40.0 : 50.0);
    expect($flag->atStand)->toBe($captured);
    expect($flag->carryingCharacter)->toBe($captured ? null : $character);
    expect($tee->score)->toBe($captured ? 5 : 0);
    $data = $controller->doSnap($tee)[1];
    assert($data instanceof ObjGameDataItem);
    expect($data->teamScoreBlue)->toBe($captured ? 100 : 0);
})->with([
    'entering capture range' => [50, 1, true],
    'leaving capture range'  => [40, -1, false],
]);

test('moving into enemy flag range grabs it in the same tick', function (int $team, bool $dropped) {
    [$world, $clock, $tee, $character] = flagMovementFixture($team);
    $flag                              = $world->getGameController()->getFlag($team ^ 1);
    assert($flag !== null);
    $flag->atStand  = ! $dropped;
    $flag->dropTick = $clock->getCurrentTick();
    $character->setPosition($flag->getPosition()->add(new Vector2(50, 0)));
    $character->vel->x = -10;
    $character->onPredictedInput(input(['direction' => -1]));

    $world->doTick();

    expect($character->getPosition()->distance($flag->standPos))->toBe(40.0);
    expect($flag->carryingCharacter)->toBe($character);
    expect($flag->atStand)->toBeFalse();
    expect($flag->getPosition())->toEqual($character->getPosition());
    expect($flag->getPosition())->not->toBe($character->getPosition());
    $snapshot = $flag->doSnap($tee)[0];
    expect(array_slice($snapshot->getInts(), 0, 2))->toBe([(int) $character->getPosition()->x, (int) $character->getPosition()->y]);
    expect($tee->score)->toBe(1);
})->with([GameConstants::TEAM_RED, GameConstants::TEAM_BLUE])->with([false, true]);

test('a ninja carrier killed after moving drops the flag at its death position', function (int $team) {
    [$world, $clock, $tee, $character] = flagMovementFixture($team);
    $flag                              = $world->getGameController()->getFlag($team ^ 1);
    assert($flag !== null);
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());
    $character->giveNinja();
    for ($i = 0; $i < 3; $i++) {
        $character->onPredictedInput(input(['targetX' => 100, 'targetY' => 0]));
    }
    $character->onDirectInput(input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]));
    $character->health = 1;
    $killer            = new PlayerTee;
    $world->addTee($killer);
    $killer->team = $team ^ 1;
    $projectile   = new PvpProjectileEntity($world, new Vector2(432, 144), new Vector2(1, 0), GameConstants::WEAPON_GUN, $killer->teeIndex);
    $projectile->setTuning(1600, 0, NetworkParams::TICKS_PER_SECOND);
    $world->addEntity($projectile);
    $clock->advanceTick();

    $world->doTick();

    expect($character->alive)->toBeFalse();
    expect($character->getPosition()->x)->toEqualWithDelta(450.0, 0.000001);
    expect($character->getPosition()->y)->toBe(144.0);
    expect($flag->carryingCharacter)->toBeNull();
    expect($flag->atStand)->toBeFalse();
    expect($flag->dropTick)->toBe($clock->getCurrentTick());
    expect($flag->vel)->toEqual(new Vector2(0, 0));
    expect($flag->getPosition())->toEqual($character->getPosition());
    expect($flag->getPosition())->not->toBe($character->getPosition());
    $snapshot = $flag->doSnap($tee)[0];
    expect(array_slice($snapshot->getInts(), 0, 2))->toBe([450, 144]);
    expect($killer->score)->toBe(2);
})->with([GameConstants::TEAM_RED, GameConstants::TEAM_BLUE]);

test('moving into dropped friendly flag range returns it in the same tick', function () {
    [$world, $clock, $tee, $character] = flagMovementFixture(GameConstants::TEAM_RED);
    $flag                              = $world->getGameController()->getFlag(GameConstants::TEAM_RED);
    assert($flag !== null);
    $flag->atStand  = false;
    $flag->dropTick = $clock->getCurrentTick();
    $flag->setPosition(new Vector2(400, 144));
    $character->setPosition(new Vector2(450, 144));
    $character->vel->x = -10;
    $character->onPredictedInput(input(['direction' => -1]));

    $world->doTick();

    expect($character->getPosition()->x)->toBe(440.0);
    expect($flag->atStand)->toBeTrue();
    expect($flag->getPosition())->toEqual($flag->standPos);
    expect($tee->score)->toBe(1);
});

test('paused and post-match worlds do not process flag captures', function (bool $gameOver) {
    [$world, , $tee, $character] = flagMovementFixture();
    $controller                  = $world->getGameController();
    $flag                        = $controller->getFlag(GameConstants::TEAM_RED);
    $ownFlag                     = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($flag !== null && $ownFlag !== null);
    $character->setPosition(clone $ownFlag->standPos);
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());
    if ($gameOver) {
        $controller->endRound();
    } else {
        $world->setPaused(true);
    }

    $world->doTick();

    expect($flag->carryingCharacter)->toBe($character);
    expect($flag->atStand)->toBeFalse();
    expect($tee->score)->toBe(0);
    expect(soundsSentToTee($world, $tee->teeIndex))->not->toContain(GameConstants::SOUND_CTF_CAPTURE);
})->with([false, true]);

test('round resets clear carried flags before the deferred flag phase', function () {
    [$world, , $tee, $character] = flagMovementFixture();
    $controller                  = $world->getGameController();
    $flag                        = $controller->getFlag(GameConstants::TEAM_RED);
    $ownFlag                     = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($flag !== null && $ownFlag !== null);
    $character->setPosition(clone $ownFlag->standPos);
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());
    $controller->startRound();

    $world->doTick();

    expect($character->isMarkedForDestruction())->toBeTrue();
    expect($flag->carryingCharacter)->toBeNull();
    expect($flag->atStand)->toBeTrue();
    expect($tee->score)->toBe(0);
    expect(soundsSentToTee($world, $tee->teeIndex))->not->toContain(GameConstants::SOUND_CTF_CAPTURE);
});

test('CTF wins from captures pause due respawns in the same tick', function () {
    [$world, $clock, $tee, $character] = flagMovementFixture(scoreLimit: 100);
    $waiting                           = new PlayerTee;
    $world->addTee($waiting);
    $waiting->respawnTick = $clock->getCurrentTick();
    $controller           = $world->getGameController();
    $flag                 = $controller->getFlag(GameConstants::TEAM_RED);
    $ownFlag              = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($flag !== null && $ownFlag !== null);
    $character->setPosition(new Vector2($ownFlag->standPos->x - 50, $ownFlag->standPos->y));
    $character->vel->x = 10;
    $character->onPredictedInput(input(['direction' => 1]));
    $flag->atStand           = false;
    $flag->carryingCharacter = $character;
    $flag->setPosition(clone $character->getPosition());

    $world->doTick();

    expect($controller->isGameOver())->toBeTrue();
    expect($world->isPaused())->toBeTrue();
    expect($tee->score)->toBe(5);
    expect($waiting->character)->toBeNull();
    expect($waiting->respawnTick)->toBe($clock->getCurrentTick() + 1);
    expect($tee->lastActionTick)->toBe($clock->getCurrentTick() + 1);
});
