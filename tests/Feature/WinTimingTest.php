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
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

/** @return array{TestPvpWorldWithController, TickHandler, PlayerTee, PlayerTee} */
function winTimingFixture(string $mode, int $scoreLimit = 1, int $timeLimit = 0): array
{
    $clock = new TickHandler(100);
    $map   = new class extends Map
    {
        public function __construct()
        {
            $tiles                    = str_repeat("\0", 32 * 16 * 4);
            $tiles[4 * (8 * 32 + 12)] = chr(GameLayer::ENTITY_SPAWN);
            $this->gameLayer          = new GameLayer(32, 16, $tiles);
            $this->collision          = $this->createCollision($this->gameLayer);
        }
    };
    $controller = new PvpGameController($clock, scoreLimit: $scoreLimit, timeLimit: $timeLimit);
    $controller->setGameMode($mode);
    $world                               = new TestPvpWorldWithController($clock, $map, $controller);
    $world->getTuneController()->gravity = 0;
    $owner                               = new PlayerTee;
    $target                              = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($target);
    $owner->team  = GameConstants::TEAM_RED;
    $target->team = $mode === 'TDM' ? GameConstants::TEAM_BLUE : GameConstants::TEAM_RED;
    foreach ([[$owner, 128], [$target, 190]] as [$tee, $x]) {
        $character = new PvpCharacterEntity($world, new Vector2($x, 128));
        $character->spawn(new Vector2($x, 128), $tee);
        $character->health = 1;
        $world->addEntity($character);
    }

    return [$world, $clock, $owner, $target];
}

function addWinTimingProjectile(TestPvpWorldWithController $world, PlayerTee $owner, int $direction = 1): void
{
    $projectile = new PvpProjectileEntity($world, new Vector2(160, 128), new Vector2($direction, 0), GameConstants::WEAPON_GUN, $owner->teeIndex);
    $projectile->setTuning(1600, 0, NetworkParams::TICKS_PER_SECOND);
    $world->addEntity($projectile);
}

test('projectile kills resolve DM and TDM wins before respawns in the same tick', function (string $mode, int $scoreLimit, bool $gameOver) {
    [$world, $clock, $owner, $target] = winTimingFixture($mode, $scoreLimit);
    $waiting                          = new PlayerTee;
    $world->addTee($waiting);
    $waiting->respawnTick = $clock->get();
    addWinTimingProjectile($world, $owner);
    $clock->next();

    $world->doTick();

    expect($owner->score)->toBe(1);
    expect($target->character)->toBeNull();
    expect($world->getGameController()->isGameOver())->toBe($gameOver);
    expect($world->isPaused())->toBe($gameOver);
    $info = $world->getGameController()->doSnap($owner)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_GAMEOVER)->toBe($gameOver ? GameConstants::GAMESTATEFLAG_GAMEOVER : 0);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_PAUSED)->toBe($gameOver ? GameConstants::GAMESTATEFLAG_PAUSED : 0);
    if ($gameOver) {
        expect($waiting->character)->toBeNull();
        expect($waiting->respawnTick)->toBe(101);
    } else {
        expect($waiting->character)->toBeInstanceOf(PvpCharacterEntity::class);
    }
})->with(['DM', 'TDM'])->with([
    'winning point'   => [1, true],
    'below the limit' => [2, false],
]);

test('simultaneous projectile kills enter sudden death using both scores from this tick', function (string $mode) {
    [$world, $clock, $owner, $target] = winTimingFixture($mode);
    addWinTimingProjectile($world, $owner);
    addWinTimingProjectile($world, $target, -1);
    $clock->next();

    $world->doTick();

    expect($owner->score)->toBe(1);
    expect($target->score)->toBe(1);
    expect($world->getGameController()->isGameOver())->toBeFalse();
    expect($world->isPaused())->toBeFalse();
    $info = $world->getGameController()->doSnap($owner)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_SUDDENDEATH)->toBe(GameConstants::GAMESTATEFLAG_SUDDENDEATH);
})->with(['DM', 'TDM']);

test('a kill on the time-limit tick is included before resolving the round', function (string $mode) {
    [$world, $clock, $owner, $target] = winTimingFixture($mode, scoreLimit: 0, timeLimit: 1);
    for ($i = 0; $i < NetworkParams::TICKS_PER_SECOND * 60 - 1; $i++) {
        $clock->next();
    }
    addWinTimingProjectile($world, $owner);
    $clock->next();

    $world->doTick();

    expect($owner->score)->toBe(1);
    expect($target->character)->toBeNull();
    expect($world->getGameController()->isGameOver())->toBeTrue();
    expect($world->isPaused())->toBeTrue();
})->with(['DM', 'TDM']);

test('DM time-limit resolution counts negative scores and only enters sudden death for a tie', function (int $firstScore, int $secondScore, bool $gameOver) {
    [$world, $clock, $first, $second] = winTimingFixture('DM', scoreLimit: 0, timeLimit: 1);
    $first->score                     = $firstScore;
    $second->score                    = $secondScore;
    for ($i = 0; $i < NetworkParams::TICKS_PER_SECOND * 60; $i++) {
        $clock->next();
    }

    $world->doTick();

    expect($world->getGameController()->isGameOver())->toBe($gameOver);
    expect($world->isPaused())->toBe($gameOver);
    $info = $world->getGameController()->doSnap($first)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_SUDDENDEATH)->toBe($gameOver ? 0 : GameConstants::GAMESTATEFLAG_SUDDENDEATH);
})->with([
    'negative leader first' => [-1, -2, true],
    'negative leader last'  => [-2, -1, true],
    'negative tie'          => [-1, -1, false],
    'zero leader'           => [0, -1, true],
    'zero tie'              => [0, 0, false],
]);

test('spectator scores cannot trigger a DM win or prevent an active player from winning', function (int $activeScore, int $spectatorScore, bool $gameOver) {
    [$world, , $first, $second] = winTimingFixture('DM', scoreLimit: 10);
    $first->score               = $activeScore;
    $second->score              = $activeScore - 1;
    $spectator                  = new PlayerTee;
    $world->addTee($spectator);
    $spectator->score = $spectatorScore;
    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);

    $world->doTick();

    expect($spectator->score)->toBe($spectatorScore);
    expect($world->getGameController()->isGameOver())->toBe($gameOver);
    expect($world->isPaused())->toBe($gameOver);
    $info = $world->getGameController()->doSnap($first)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_SUDDENDEATH)->toBe(0);
})->with([
    'spectator ties the leader'        => [10, 10, true],
    'spectator exceeds the leader'     => [10, 11, true],
    'only spectator reaches the limit' => [9, 10, false],
]);

test('a DM world without active players does not declare a winner or sudden death', function () {
    [$world, $clock, $first, $second] = winTimingFixture('DM', scoreLimit: 10, timeLimit: 1);
    $first->score                     = 10;
    $first->setTeam(GameConstants::TEAM_SPECTATORS);
    $second->setTeam(GameConstants::TEAM_SPECTATORS);
    for ($i = 0; $i < NetworkParams::TICKS_PER_SECOND * 60; $i++) {
        $clock->next();
    }

    $world->doTick();

    expect($world->getGameController()->isGameOver())->toBeFalse();
    expect($world->isPaused())->toBeFalse();
    $info = $world->getGameController()->doSnap($first)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags & GameConstants::GAMESTATEFLAG_SUDDENDEATH)->toBe(0);
});

test('warmup still prevents a projectile kill from ending the round', function (string $mode) {
    [$world, $clock, $owner] = winTimingFixture($mode);
    $world->getGameController()->doWarmup(10);
    addWinTimingProjectile($world, $owner);
    $clock->next();

    $world->doTick();

    expect($owner->score)->toBe(1);
    expect($world->getGameController()->isGameOver())->toBeFalse();
    expect($world->isPaused())->toBeFalse();
})->with(['DM', 'TDM']);
