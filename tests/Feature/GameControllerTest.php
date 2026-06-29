<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

/**
 * A controller that runs the shared tick() + doWincheck() logic,
 * so we can exercise warmup, game-over, balancing and inactive kick.
 *
 * @param  array<string, mixed>  $opts
 */
function makeGameController(TickHandler $tickHandler, array $opts = []): AbstractGameController
{
    return new class($tickHandler, $opts['isTeamMode'] ?? false, $opts['isCaptureTheFlag'] ?? false, $opts['scoreLimit'] ?? 0, $opts['timeLimit'] ?? 0, $opts['teamBalanceTime'] ?? 0, $opts['inactiveKickTime'] ?? 0, $opts['inactiveKick'] ?? 0, $opts['spectatorSlots'] ?? 0) extends AbstractGameController
    {
        public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
        {
            return 0;
        }
    };
}

function createWorldWithController(Map $map, TickHandler $tickHandler, AbstractGameController $controller): AbstractWorld
{
    return new TestWorldWithController($tickHandler, $map, $controller);
}

/*
|--------------------------------------------------------------------------
| Warmup
|--------------------------------------------------------------------------
*/

function setGameTick(TickHandler $tickHandler, int $tick): void
{
    $ref  = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setValue($tickHandler, $tick);
}

test('warmup counts down and starts round when it reaches zero', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 20]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $controller->doWarmup(3); // 3 seconds = 150 ticks
    expect($controller->isGameOver())->toBeFalse();

    // Tick partway through warmup — should still be in warmup
    setGameTick($tickHandler, 200);
    $world->doTick();
    expect($controller->isGameOver())->toBeFalse();

    // Tick past the warmup end — should start the round
    setGameTick($tickHandler, 251);
    $world->doTick();
    expect($controller->isGameOver())->toBeFalse();
});

test('doWarmup with negative seconds clears warmup', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler);
    createWorldWithController($map, $tickHandler, $controller);

    $controller->doWarmup(5);
    $controller->doWarmup(-1);

    // Warmup should be 0 — the round starts immediately on next tick
    setGameTick($tickHandler, 101);
    expect($controller->isGameOver())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Game over and restart
|--------------------------------------------------------------------------
*/

test('round ends when a tee reaches the score limit', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 3]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Winner';
    $world->addTee($tee);

    $tee->score = 3;

    $world->doTick();

    expect($controller->isGameOver())->toBeTrue();
});

test('round does not end during warmup even if score limit reached', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 3]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'WarmupKiller';
    $world->addTee($tee);

    $controller->doWarmup(10);
    $tee->score = 5;

    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();
});

test('game over pauses the world', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Scorer';
    $world->addTee($tee);

    $tee->score = 1;
    $world->doTick();

    expect($controller->isGameOver())->toBeTrue();
    expect($world->isPaused())->toBeTrue();
});

test('round restarts after the 10 second game-over pause', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'RestartTee';
    $world->addTee($tee);

    // Trigger game over
    $tee->score = 1;
    $world->doTick();
    expect($controller->isGameOver())->toBeTrue();

    // Tick forward 10 seconds + 1 tick — should restart the round
    setGameTick($tickHandler, 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1);
    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();
    expect($world->isPaused())->toBeFalse();
    // Scores should be reset by the restart
    expect($tee->score)->toBe(0);
});

test('sudden death triggers when scores are tied at the limit', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'scoreLimit' => 2]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red        = new PlayerTee;
    $red->name  = 'Red';
    $red->score = 2;
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $blue        = new PlayerTee;
    $blue->name  = 'Blue';
    $blue->score = 2;
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    $world->doTick();

    // Tied scores → sudden death, not game over
    expect($controller->isGameOver())->toBeFalse();
});

test('team mode round ends when one team exceeds the other at score limit', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'scoreLimit' => 2]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red        = new PlayerTee;
    $red->name  = 'Red';
    $red->score = 3;
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $blue        = new PlayerTee;
    $blue->name  = 'Blue';
    $blue->score = 1;
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    $world->doTick();

    expect($controller->isGameOver())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Snap output
|--------------------------------------------------------------------------
*/

test('doSnap reflects game over and paused state flags', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'SnapTee';
    $world->addTee($tee);

    // Normal state — no flags
    $snaps    = $controller->doSnap($tee);
    $gameInfo = $snaps[0];
    assert($gameInfo instanceof ObjGameInfoItem);
    expect($gameInfo->gameStateFlags)->toBe(0);

    // Trigger game over
    $tee->score = 1;
    $world->doTick();

    $snaps    = $controller->doSnap($tee);
    $gameInfo = $snaps[0];
    assert($gameInfo instanceof ObjGameInfoItem);
    expect($gameInfo->gameStateFlags & GameConstants::GAMESTATEFLAG_GAMEOVER)->not()->toBe(0);
    expect($gameInfo->gameStateFlags & GameConstants::GAMESTATEFLAG_PAUSED)->not()->toBe(0);
    expect($gameInfo->scoreLimit)->toBe(1);
});

test('doSnap includes team scores in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'scoreLimit' => 10]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red        = new PlayerTee;
    $red->name  = 'Red';
    $red->score = 5;
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $blue        = new PlayerTee;
    $blue->name  = 'Blue';
    $blue->score = 3;
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    $snaps    = $controller->doSnap($red);
    $gameData = $snaps[1];
    assert($gameData instanceof ObjGameDataItem);
    expect($gameData->getItemId())->toBe(NetworkMessages::NETOBJTYPE_GAMEDATA);
    expect($gameData->teamScoreRed)->toBe(5);
    expect($gameData->teamScoreBlue)->toBe(3);
});

test('doSnap emits GAMEFLAG_TEAMS in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'FlagCheck';
    $world->addTee($tee);

    $snaps    = $controller->doSnap($tee);
    $gameInfo = $snaps[0];
    assert($gameInfo instanceof ObjGameInfoItem);
    expect($gameInfo->gameFlags & GameConstants::GAMEFLAG_TEAMS)->not()->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Team balancing
|--------------------------------------------------------------------------
*/

test('checkTeamBalance marks teams as unbalanced when diff is 2 or more', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'teamBalanceTime' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    for ($i = 0; $i < 3; $i++) {
        $tee       = new PlayerTee;
        $tee->name = "Red{$i}";
        $world->addTee($tee);
        $tee->team = GameConstants::TEAM_RED;
    }

    $blue       = new PlayerTee;
    $blue->name = 'Blue0';
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    expect($controller->checkTeamBalance())->toBeFalse();
});

test('checkTeamBalance returns true when teams are even', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'teamBalanceTime' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red       = new PlayerTee;
    $red->name = 'Red';
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $blue       = new PlayerTee;
    $blue->name = 'Blue';
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    expect($controller->checkTeamBalance())->toBeTrue();
});

test('team balancing moves a player from the larger team after the balance time elapses', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    // 1 minute balance time
    $controller = makeGameController($tickHandler, ['isTeamMode' => true, 'teamBalanceTime' => 1]);
    $world      = createWorldWithController($map, $tickHandler, $controller);

    // 3 reds, 1 blue → diff of 2
    $red1       = new PlayerTee;
    $red1->name = 'R1';
    $world->addTee($red1);
    $red1->team = GameConstants::TEAM_RED;
    $red2       = new PlayerTee;
    $red2->name = 'R2';
    $world->addTee($red2);
    $red2->team = GameConstants::TEAM_RED;
    $red3       = new PlayerTee;
    $red3->name = 'R3';
    $world->addTee($red3);
    $red3->team = GameConstants::TEAM_RED;
    $blue       = new PlayerTee;
    $blue->name = 'B1';
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    // Trigger the unbalanced detection
    expect($controller->checkTeamBalance())->toBeFalse();

    // Advance past the balance time (1 minute = 3000 ticks)
    setGameTick($tickHandler, 100 + 3001);
    $world->doTick();

    // One red should have been moved to blue → 2 reds, 2 blues
    $reds  = 0;
    $blues = 0;
    foreach ($world->getTees() as $tee) {
        if (! $tee instanceof PlayerTee) {
            continue;
        }
        if ($tee->team === GameConstants::TEAM_RED) {
            $reds++;
        }
        if ($tee->team === GameConstants::TEAM_BLUE) {
            $blues++;
        }
    }

    expect($reds)->toBe(2);
    expect($blues)->toBe(2);
});

test('team balancing is disabled when teamBalanceTime is zero', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true, 'teamBalanceTime' => 0]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red1       = new PlayerTee;
    $red1->name = 'R1';
    $world->addTee($red1);
    $red1->team = GameConstants::TEAM_RED;
    $red2       = new PlayerTee;
    $red2->name = 'R2';
    $world->addTee($red2);
    $red2->team = GameConstants::TEAM_RED;

    // checkTeamBalance returns true (disabled) even though teams are uneven
    expect($controller->checkTeamBalance())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Inactive kick
|--------------------------------------------------------------------------
*/

test('inactive kick moves player to spectators after the timeout', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, [
        'inactiveKickTime' => 1, // 1 minute
        'inactiveKick'     => AbstractGameController::INACTIVE_KICK_TO_SPECTATOR,
    ]);
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee                 = new PlayerTee;
    $tee->name           = 'Idle';
    $tee->lastActionTick = 100;
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_RED;

    // Advance past the inactivity threshold (1 minute = 3000 ticks)
    setGameTick($tickHandler, 100 + 3001);
    $world->doTick();

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
});

test('inactive kick is disabled when inactiveKickTime is zero', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, [
        'inactiveKickTime' => 0,
        'inactiveKick'     => AbstractGameController::INACTIVE_KICK_TO_SPECTATOR,
    ]);
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee                 = new PlayerTee;
    $tee->name           = 'StillHere';
    $tee->lastActionTick = 100;
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_RED;

    setGameTick($tickHandler, 100 + 10000);
    $world->doTick();

    expect($tee->team)->toBe(GameConstants::TEAM_RED);
});

test('inactive kick does not affect spectators', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, [
        'inactiveKickTime' => 1,
        'inactiveKick'     => AbstractGameController::INACTIVE_KICK_TO_SPECTATOR,
    ]);
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee                 = new PlayerTee;
    $tee->name           = 'Spec';
    $tee->lastActionTick = 100;
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_SPECTATORS;

    setGameTick($tickHandler, 100 + 3001);
    $world->doTick();

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
});

test('inactive kick in kick mode kicks the player', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, [
        'inactiveKickTime' => 1,
        'inactiveKick'     => AbstractGameController::INACTIVE_KICK_KICK,
    ]);
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee                 = new PlayerTee;
    $tee->name           = 'ToKick';
    $tee->lastActionTick = 100;
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_RED;

    setGameTick($tickHandler, 100 + 3001);
    $world->doTick();

    expect($GLOBALS['mockGameServer']->kickedTees)->toHaveKey($tee->teeIndex);
    expect($GLOBALS['mockGameServer']->kickedTees[$tee->teeIndex])->toBe('Kicked for inactivity');
});

test('active player is not kicked for inactivity', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, [
        'inactiveKickTime' => 1,
        'inactiveKick'     => AbstractGameController::INACTIVE_KICK_TO_SPECTATOR,
    ]);
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee                 = new PlayerTee;
    $tee->name           = 'Active';
    $tee->lastActionTick = 100;
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_RED;

    // Advance but keep the player active (lastActionTick updated)
    setGameTick($tickHandler, 200);
    $tee->lastActionTick = 200;
    $world->doTick();

    expect($tee->team)->toBe(GameConstants::TEAM_RED);
});

/*
|--------------------------------------------------------------------------
| Pause freezes the simulation
|--------------------------------------------------------------------------
*/

test('paused world does not apply input to characters', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Walker';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);

    // Trigger game over → world pauses
    $tee->score = 1;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    // Capture position after the pause is in effect
    $posBefore = clone $character->getPosition();

    // Feed a movement input while paused
    $tee->inputs[102] = new PlayerInput(
        direction: 1, targetX: 100, targetY: 0, jump: false,
        fire: 0, hook: false, playerFlags: 0,
        wantedWeapon: 0, nextWeapon: 0, prevWeapon: 0,
    );

    setGameTick($tickHandler, 102);
    $world->doTick();

    // Character should not have moved — simulation is frozen
    $posAfter = $character->getPosition();
    expect($posAfter->x)->toBe($posBefore->x);
    expect($posAfter->y)->toBe($posBefore->y);
});

test('paused world does not tick entities (no gravity)', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Faller';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);

    // Trigger game over → world pauses
    $tee->score = 1;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    // Capture position after the pause is in effect
    $posBefore = clone $character->getPosition();

    // Advance several ticks while paused
    setGameTick($tickHandler, 110);
    $world->doTick();
    setGameTick($tickHandler, 120);
    $world->doTick();

    // Character should not have moved at all — no gravity, no physics
    $posAfter = $character->getPosition();
    expect($posAfter->x)->toBe($posBefore->x);
    expect($posAfter->y)->toBe($posBefore->y);
});

/*
|--------------------------------------------------------------------------
| Friendly fire
|--------------------------------------------------------------------------
*/

test('isFriendlyFire returns false in non-team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => false]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee1       = new PlayerTee;
    $tee1->name = 'A';
    $world->addTee($tee1);
    $tee2       = new PlayerTee;
    $tee2->name = 'B';
    $world->addTee($tee2);

    expect($controller->isFriendlyFire($tee1->teeIndex, $tee2->teeIndex))->toBeFalse();
});

test('isFriendlyFire returns true for same-team players in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red1       = new PlayerTee;
    $red1->name = 'R1';
    $world->addTee($red1);
    $red1->team = GameConstants::TEAM_RED;
    $red2       = new PlayerTee;
    $red2->name = 'R2';
    $world->addTee($red2);
    $red2->team = GameConstants::TEAM_RED;

    expect($controller->isFriendlyFire($red1->teeIndex, $red2->teeIndex))->toBeTrue();
});

test('isFriendlyFire returns false for different teams in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $red       = new PlayerTee;
    $red->name = 'R';
    $world->addTee($red);
    $red->team  = GameConstants::TEAM_RED;
    $blue       = new PlayerTee;
    $blue->name = 'B';
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    expect($controller->isFriendlyFire($red->teeIndex, $blue->teeIndex))->toBeFalse();
});

test('isFriendlyFire returns false for self-damage', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Self';
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_RED;

    expect($controller->isFriendlyFire($tee->teeIndex, $tee->teeIndex))->toBeFalse();
});

test('same-team takeDamage deals no damage in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $attackerTee       = new PlayerTee;
    $attackerTee->name = 'Att';
    $world->addTee($attackerTee);
    $attackerTee->team = GameConstants::TEAM_RED;
    $attacker          = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    $victimTee       = new PlayerTee;
    $victimTee->name = 'Vic';
    $world->addTee($victimTee);
    $victimTee->team = GameConstants::TEAM_RED;
    $victim          = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $victimTee);
    $world->addEntity($victim);

    $healthBefore = $victim->health;
    $victim->takeDamage(new Vector2(0, 0), 5, $attacker);

    // Same team → no damage
    expect($victim->health)->toBe($healthBefore);
    expect($victim->alive)->toBeTrue();
});

test('different-team takeDamage deals damage in team mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $attackerTee       = new PlayerTee;
    $attackerTee->name = 'Att';
    $world->addTee($attackerTee);
    $attackerTee->team = GameConstants::TEAM_RED;
    $attacker          = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    $victimTee       = new PlayerTee;
    $victimTee->name = 'Vic';
    $world->addTee($victimTee);
    $victimTee->team = GameConstants::TEAM_BLUE;
    $victim          = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $victimTee);
    $world->addEntity($victim);

    $healthBefore = $victim->health;
    $victim->takeDamage(new Vector2(0, 0), 5, $attacker);

    // Different team → damage applied
    expect($victim->health)->toBeLessThan($healthBefore);
});

test('non-team mode takeDamage always deals damage', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => false]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $attackerTee       = new PlayerTee;
    $attackerTee->name = 'Att';
    $world->addTee($attackerTee);
    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $attackerTee);
    $world->addEntity($attacker);

    $victimTee       = new PlayerTee;
    $victimTee->name = 'Vic';
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $victimTee);
    $world->addEntity($victim);

    $healthBefore = $victim->health;
    $victim->takeDamage(new Vector2(0, 0), 5, $attacker);

    expect($victim->health)->toBeLessThan($healthBefore);
});

test('self-damage is halved', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => false]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $spawnPos = new Vector2(50 * 32, 25 * 32);

    $tee       = new PlayerTee;
    $tee->name = 'Self';
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);

    $healthBefore = $character->health;
    // Self-damage of 4 → halved to max(1, 4/2) = 2
    $character->takeDamage(new Vector2(0, 0), 4, $character);

    expect($character->health)->toBe($healthBefore - 2);
});

/*
|--------------------------------------------------------------------------
| Spawn team routing
|--------------------------------------------------------------------------
*/

test('tryRespawnTee passes the tee team to canSpawn', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);

    // A controller that records the team argument passed to canSpawn
    $controller = new class($tickHandler, true) extends AbstractGameController
    {
        public int $lastSpawnTeam = -99;

        public function doTick(): void
        {
            // no-op: let the world drive respawning without controller logic
        }

        public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
        {
            return 0;
        }

        public function canSpawn(AbstractWorld $world, int $team): ?Vector2
        {
            $this->lastSpawnTeam = $team;

            return parent::canSpawn($world, $team);
        }
    };
    $world = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'BlueSpawn';
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_BLUE;

    $world->doTick();

    // canSpawn should have been called with TEAM_BLUE (1), not TEAM_RED (0)
    expect($controller->lastSpawnTeam)->toBe(GameConstants::TEAM_BLUE);
});

/*
|--------------------------------------------------------------------------
| Auto-team on connect
|--------------------------------------------------------------------------
*/

test('first player in team mode joins red team automatically', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'First';
    $world->addTee($tee);

    expect($tee->team)->toBe(GameConstants::TEAM_RED);
});

test('second player in team mode joins blue team automatically', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $first       = new PlayerTee;
    $first->name = 'First';
    $world->addTee($first);
    expect($first->team)->toBe(GameConstants::TEAM_RED);

    $second       = new PlayerTee;
    $second->name = 'Second';
    $world->addTee($second);
    expect($second->team)->toBe(GameConstants::TEAM_BLUE);
});

test('third player in team mode joins the team with fewer players', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => true]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $first       = new PlayerTee;
    $first->name = 'First';
    $world->addTee($first);
    $second       = new PlayerTee;
    $second->name = 'Second';
    $world->addTee($second);

    expect($first->team)->toBe(GameConstants::TEAM_RED);
    expect($second->team)->toBe(GameConstants::TEAM_BLUE);

    $third       = new PlayerTee;
    $third->name = 'Third';
    $world->addTee($third);
    expect($third->team)->toBe(GameConstants::TEAM_RED);
});

test('non-team mode always assigns red team', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['isTeamMode' => false]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $first       = new PlayerTee;
    $first->name = 'First';
    $world->addTee($first);
    $second       = new PlayerTee;
    $second->name = 'Second';
    $world->addTee($second);

    expect($first->team)->toBe(GameConstants::TEAM_RED);
    expect($second->team)->toBe(GameConstants::TEAM_RED);
});

/*
|--------------------------------------------------------------------------
| Round restart respawns alive characters
|--------------------------------------------------------------------------
*/

test('round restart destroys existing characters and respawns everyone', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    $tee       = new PlayerTee;
    $tee->name = 'Restart';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);
    expect($character->alive)->toBeTrue();

    $originalPos = clone $character->getPosition();

    // Trigger game over
    $tee->score = 1;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    // Advance past the 10s game-over pause
    setGameTick($tickHandler, 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1);
    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();
    expect($world->isPaused())->toBeFalse();

    // The old character should have been destroyed — tee has no character yet
    expect($tee->character)->toBeNull();
    expect($tee->spawning)->toBeTrue();
    expect($tee->score)->toBe(0);

    // Tick forward to respawn
    setGameTick($tickHandler, 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1 + 25);
    $world->doTick();

    $newCharacter = $tee->character;
    assert($newCharacter instanceof PvpCharacterEntity);
    expect($newCharacter)->not()->toBe($character);
    expect($newCharacter->alive)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Pickups survive round restart
|--------------------------------------------------------------------------
*/

test('pickups survive round restart and reset their spawn tick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    // Add a health pickup and an armor pickup to the world
    $healthPos    = new Vector2(50 * 32, 25 * 32);
    $armorPos     = new Vector2(51 * 32, 25 * 32);
    $healthPickup = new PickupEntity($world, $healthPos, GameConstants::POWERUP_HEALTH, respawnTime: 100, spawnDelay: 0);
    $armorPickup  = new PickupEntity($world, $armorPos, GameConstants::POWERUP_ARMOR, respawnTime: 100, spawnDelay: 0);
    $world->addEntity($healthPickup);
    $world->addEntity($armorPickup);

    $tee       = new PlayerTee;
    $tee->name = 'Restart';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();

    $entityCountBefore = count($world->getEntities());
    expect($entityCountBefore)->toBe(3); // 1 character + 2 pickups

    // Trigger game over
    $tee->score = 1;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    // Advance past the 10s game-over pause
    setGameTick($tickHandler, 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1);
    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();

    // Pickups should still be in the world (character was destroyed, will respawn later)
    $pickups = array_filter($world->getEntities(), fn ($e) => $e instanceof PickupEntity);
    expect(count($pickups))->toBe(2);
});

test('pickups with spawn delay re-enter spawn delay after round restart', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $controller  = makeGameController($tickHandler, ['scoreLimit' => 1]);
    $world       = createWorldWithController($map, $tickHandler, $controller);

    // Add a pickup with a spawn delay of 50 ticks
    $pos    = new Vector2(50 * 32, 25 * 32);
    $pickup = new PickupEntity($world, $pos, GameConstants::POWERUP_HEALTH, respawnTime: 100, spawnDelay: 50);
    $world->addEntity($pickup);

    $tee       = new PlayerTee;
    $tee->name = 'Restart';
    $world->addTee($tee);

    // Spawn the character and let the pickup become available
    $world->doTick();

    // Trigger game over
    $tee->score = 1;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    $restartTick = 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1;
    setGameTick($tickHandler, $restartTick);
    $world->doTick();

    // The pickup should have been reset with a spawn delay relative to the restart tick
    // Verify it still exists and is in spawn delay (not yet snap-able)
    $pickups = array_filter($world->getEntities(), fn ($e) => $e instanceof PickupEntity);
    expect(count($pickups))->toBe(1);

    // The pickup snap should be empty while in spawn delay
    $snaps = $pickup->doSnap($tee);
    expect($snaps)->toBeEmpty();

    // After the spawn delay elapses, the pickup should be snap-able again
    setGameTick($tickHandler, $restartTick + 51);
    $snaps = $pickup->doSnap($tee);
    expect($snaps)->not()->toBeEmpty();
});
