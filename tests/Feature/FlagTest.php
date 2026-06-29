<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjFlagItem;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

$ctfMapPath = __DIR__ . '/../ctf1.map';
$ctfMapExists = file_exists($ctfMapPath);

/**
 * Build a CTF-enabled game controller for tests.
     *
 * @param array<string, mixed> $opts
 */
function makeCtfGameController(TickHandler $tickHandler, array $opts = []): AbstractGameController
{
    return new class(
        $tickHandler,
        true, // isTeamMode
        true, // isCaptureTheFlag
        $opts['scoreLimit'] ?? 0,
        $opts['timeLimit'] ?? 0,
        $opts['teamBalanceTime'] ?? 0,
        $opts['inactiveKickTime'] ?? 0,
        $opts['inactiveKick'] ?? 0,
        $opts['spectatorSlots'] ?? 0,
    ) extends AbstractGameController {
    };
}

function createCtfWorld(Map $map, TickHandler $tickHandler, AbstractGameController $controller): AbstractWorld
{
    return new TestWorldWithController($tickHandler, $map, $controller);
}

function setCtfTick(TickHandler $tickHandler, int $tick): void
{
    $ref = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setAccessible(true);
    $prop->setValue($tickHandler, $tick);
}

/*
|--------------------------------------------------------------------------
| Flag creation from map
|--------------------------------------------------------------------------
*/

test('CTF controller creates flags from flag stand entities', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    $blueFlag = $controller->getFlag(GameConstants::TEAM_BLUE);

    expect($redFlag)->not()->toBeNull();
    expect($blueFlag)->not()->toBeNull();
    assert($redFlag instanceof FlagEntity);
    assert($blueFlag instanceof FlagEntity);
    expect($redFlag->team)->toBe(GameConstants::TEAM_RED);
    expect($blueFlag->team)->toBe(GameConstants::TEAM_BLUE);
    expect($redFlag->atStand)->toBeTrue();
    expect($blueFlag->atStand)->toBeTrue();
});

test('CTF flags are added to the world entity list', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $flags = array_filter($world->getEntities(), fn ($e) => $e instanceof FlagEntity);
    expect(count($flags))->toBe(2);
});

test('non-CTF controller does not create flags from flag stands', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = new class($tickHandler, true, false) extends AbstractGameController {
    };
    $world = createCtfWorld($map, $tickHandler, $controller);

    expect($controller->getFlag(GameConstants::TEAM_RED))->toBeNull();
    expect($controller->getFlag(GameConstants::TEAM_BLUE))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Flag snap output
|--------------------------------------------------------------------------
*/

test('CTF doSnap emits GAMEFLAG_TEAMS and GAMEFLAG_FLAGS', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $tee = new PlayerTee;
    $tee->name = 'Snap';
    $world->addTee($tee);

    $snaps = $controller->doSnap($tee);
    $gameInfo = $snaps[0];
    assert($gameInfo instanceof ObjGameInfoItem);
    expect($gameInfo->gameFlags & GameConstants::GAMEFLAG_TEAMS)->not()->toBe(0);
    expect($gameInfo->gameFlags & GameConstants::GAMEFLAG_FLAGS)->not()->toBe(0);
});

test('CTF doSnap emits ObjGameData with FLAG_ATSTAND when flags are at stand', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $tee = new PlayerTee;
    $tee->name = 'Snap';
    $world->addTee($tee);

    $snaps = $controller->doSnap($tee);
    $gameData = $snaps[1];
    assert($gameData instanceof ObjGameDataItem);
    expect($gameData->flagCarrierRedIndex)->toBe(GameConstants::FLAG_ATSTAND);
    expect($gameData->flagCarrierBlueIndex)->toBe(GameConstants::FLAG_ATSTAND);
});

test('CTF doSnap emits ObjFlagItem for each flag', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $tee = new PlayerTee;
    $tee->name = 'Snap';
    $world->addTee($tee);
    // Spectators see all entities (no distance culling)
    $tee->team = GameConstants::TEAM_SPECTATORS;

    $snaps = $world->doSnap($tee);
    $flagSnaps = array_filter($snaps, fn ($s) => $s instanceof ObjFlagItem);
    expect(count($flagSnaps))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Flag grab
|--------------------------------------------------------------------------
*/

test('enemy player can grab a flag from the stand', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Blue player at the red flag stand
    $blueTee = new PlayerTee;
    $blueTee->name = 'BlueGrabber';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $redFlagPos = $redFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $redFlagPos);
    $character->spawn(clone $redFlagPos, $blueTee);
    $world->addEntity($character);

    $world->doTick();

    expect($redFlag->atStand)->toBeFalse();
    expect($redFlag->carryingCharacter)->toBe($character);
    // +1 team score (from grab) +1 personal score = 2
    expect($blueTee->score)->toBe(2);
});

test('same-team player cannot grab their own flag from the stand', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $redTee = new PlayerTee;
    $redTee->name = 'RedDefender';
    $world->addTee($redTee);
    $redTee->team = GameConstants::TEAM_RED;

    $redFlagPos = $redFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $redFlagPos);
    $character->spawn(clone $redFlagPos, $redTee);
    $world->addEntity($character);

    $world->doTick();

    expect($redFlag->atStand)->toBeTrue();
    expect($redFlag->carryingCharacter)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Flag return
|--------------------------------------------------------------------------
*/

test('same-team player can return a dropped flag', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Simulate a dropped flag (not at stand, not carried)
    $redFlag->atStand = false;
    $redFlag->setPosition(new Vector2($redFlag->standPos->x + 100, $redFlag->standPos->y));
    $redFlag->dropTick = 100;

    $redTee = new PlayerTee;
    $redTee->name = 'RedReturner';
    $world->addTee($redTee);
    $redTee->team = GameConstants::TEAM_RED;

    $redFlagPos = $redFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $redFlagPos);
    $character->spawn(clone $redFlagPos, $redTee);
    $world->addEntity($character);

    $world->doTick();

    expect($redFlag->atStand)->toBeTrue();
    expect($redFlag->carryingCharacter)->toBeNull();
    expect($redTee->score)->toBe(1); // +1 for returning
});

/*
|--------------------------------------------------------------------------
| Flag capture
|--------------------------------------------------------------------------
*/

test('carrying the enemy flag to your own stand captures it', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    $blueFlag = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($redFlag !== null);
    assert($blueFlag !== null);

    // Blue player carrying the red flag, at the blue stand
    $blueTee = new PlayerTee;
    $blueTee->name = 'BlueCapper';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $blueFlagPos = $blueFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $blueFlagPos);
    $character->spawn(clone $blueFlagPos, $blueTee);
    $world->addEntity($character);

    // Simulate the blue player carrying the red flag
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;
    $redFlag->setPosition(clone $blueFlag->getPosition());

    $world->doTick();

    // Both flags should be reset to their stands
    expect($redFlag->atStand)->toBeTrue();
    expect($blueFlag->atStand)->toBeTrue();
    expect($redFlag->carryingCharacter)->toBeNull();

    // Capture awards +100 to the carrier's score
    expect($blueTee->score)->toBe(100);
});

test('capture does not happen when own flag is not at stand', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    $blueFlag = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($redFlag !== null);
    assert($blueFlag !== null);

    // Blue flag is NOT at stand (enemy took it)
    $blueFlag->atStand = false;
    // Move the blue flag away so the blue player doesn't return it
    $blueFlag->setPosition(new Vector2(999, 999));

    $blueTee = new PlayerTee;
    $blueTee->name = 'BlueCapper';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $character = new PvpCharacterEntity($world, clone $blueFlag->standPos);
    $character->spawn(clone $blueFlag->standPos, $blueTee);
    $world->addEntity($character);

    // Blue player carrying the red flag, at the blue stand position
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;
    $redFlag->setPosition(clone $blueFlag->standPos);

    $world->doTick();

    // No capture — red flag should still be carried
    expect($redFlag->carryingCharacter)->toBe($character);
    expect($blueTee->score)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Flag drop on death
|--------------------------------------------------------------------------
*/

test('flag is dropped when the carrier dies', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $blueTee = new PlayerTee;
    $blueTee->name = 'BlueCarrier';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $character = new PvpCharacterEntity($world, new Vector2(500, 500));
    $character->spawn(new Vector2(500, 500), $blueTee);
    $world->addEntity($character);

    // Blue player carrying the red flag
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;

    // Kill the carrier
    $character->die(-1, GameConstants::WEAPON_WORLD);

    expect($redFlag->carryingCharacter)->toBeNull();
    expect($redFlag->atStand)->toBeFalse();
    expect($redFlag->dropTick)->toBe(100);
});

/*
|--------------------------------------------------------------------------
| Flag auto-return after 30 seconds
|--------------------------------------------------------------------------
*/

test('dropped flag auto-returns after 30 seconds', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Simulate a dropped flag
    $redFlag->atStand = false;
    $redFlag->setPosition(new Vector2($redFlag->standPos->x + 100, $redFlag->standPos->y));
    $redFlag->dropTick = 100;

    // Advance 31 seconds (1550 ticks at 50 tps)
    setCtfTick($tickHandler, 100 + 1551);
    $world->doTick();

    expect($redFlag->atStand)->toBeTrue();
    expect($redFlag->getPosition()->x)->toBe($redFlag->standPos->x);
    expect($redFlag->getPosition()->y)->toBe($redFlag->standPos->y);
});

test('dropped flag does not auto-return before 30 seconds', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $dropPos = new Vector2($redFlag->standPos->x + 100, $redFlag->standPos->y);
    $redFlag->atStand = false;
    $redFlag->setPosition(clone $dropPos);
    $redFlag->dropTick = 100;

    // Advance 29 seconds (1450 ticks)
    setCtfTick($tickHandler, 100 + 1451);
    $world->doTick();

    expect($redFlag->atStand)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Flag survives round restart
|--------------------------------------------------------------------------
*/

test('flags survive round restart and return to their stands', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler, ['scoreLimit' => 100]);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Simulate a taken flag
    $redFlag->atStand = false;
    $redFlag->setPosition(new Vector2(999, 999));

    $tee = new PlayerTee;
    $tee->name = 'Restart';
    $world->addTee($tee);

    // Trigger game over
    $tee->score = 100;
    $world->doTick();
    expect($world->isPaused())->toBeTrue();

    // Advance past the 10s game-over pause
    setCtfTick($tickHandler, 100 + (int) (NetworkParams::TICKS_PER_SECOND * 10) + 1);
    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();

    // Flag should be back at its stand
    expect($redFlag->atStand)->toBeTrue();
    expect($redFlag->getPosition()->x)->toBe($redFlag->standPos->x);
    expect($redFlag->getPosition()->y)->toBe($redFlag->standPos->y);

    // Flag should still be in the world
    $flags = array_filter($world->getEntities(), fn ($e) => $e instanceof FlagEntity);
    expect(count($flags))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| canBeMovedOnBalance
|--------------------------------------------------------------------------
*/

test('player carrying a flag cannot be moved by team balancer', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $blueTee = new PlayerTee;
    $blueTee->name = 'Carrier';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $character = new PvpCharacterEntity($world, new Vector2(500, 500));
    $character->spawn(new Vector2(500, 500), $blueTee);
    $world->addEntity($character);

    // Not carrying → can be moved
    expect($controller->canBeMovedOnBalance($blueTee->teeIndex))->toBeTrue();

    // Carrying the red flag → cannot be moved
    $redFlag->carryingCharacter = $character;
    expect($controller->canBeMovedOnBalance($blueTee->teeIndex))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CTF win check
|--------------------------------------------------------------------------
*/

test('CTF round ends when a team reaches the score limit', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler, ['scoreLimit' => 1000]);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $red = new PlayerTee;
    $red->name = 'Red';
    $red->score = 1000;
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $world->doTick();

    expect($controller->isGameOver())->toBeTrue();
});

test('CTF sudden death triggers when scores are tied at the limit', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler, ['scoreLimit' => 1000]);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $red = new PlayerTee;
    $red->name = 'Red';
    $red->score = 1000;
    $world->addTee($red);
    $red->team = GameConstants::TEAM_RED;

    $blue = new PlayerTee;
    $blue->name = 'Blue';
    $blue->score = 1000;
    $world->addTee($blue);
    $blue->team = GameConstants::TEAM_BLUE;

    $world->doTick();

    expect($controller->isGameOver())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Flag carrier in snap
|--------------------------------------------------------------------------
*/

test('CTF doSnap reports the carrier tee index when a flag is carried', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $blueTee = new PlayerTee;
    $blueTee->name = 'Carrier';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    $character = new PvpCharacterEntity($world, new Vector2(500, 500));
    $character->spawn(new Vector2(500, 500), $blueTee);
    $world->addEntity($character);

    // Blue player carrying the red flag
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;

    $snaps = $controller->doSnap($blueTee);
    $gameData = $snaps[1];
    assert($gameData instanceof ObjGameDataItem);
    expect($gameData->flagCarrierRedIndex)->toBe($blueTee->teeIndex);
    expect($gameData->flagCarrierBlueIndex)->toBe(GameConstants::FLAG_ATSTAND);
});

test('CTF doSnap reports FLAG_TAKEN when a flag is dropped', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Simulate a dropped flag
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = null;

    $tee = new PlayerTee;
    $tee->name = 'Snap';
    $world->addTee($tee);

    $snaps = $controller->doSnap($tee);
    $gameData = $snaps[1];
    assert($gameData instanceof ObjGameDataItem);
    expect($gameData->flagCarrierRedIndex)->toBe(GameConstants::FLAG_TAKEN);
});

/*
|--------------------------------------------------------------------------
| CTF global sounds
|--------------------------------------------------------------------------
*/

test('CTF flag grab sends grab sounds to the correct teams', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    // Red player (defending team) and blue player (attacking team)
    $redTee = new PlayerTee; $redTee->name = 'Red'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;
    $blueTee = new PlayerTee; $blueTee->name = 'Blue'; $world->addTee($blueTee); $blueTee->team = GameConstants::TEAM_BLUE;

    // Blue player at the red flag stand → grabs the red flag
    $redFlagPos = $redFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $redFlagPos);
    $character->spawn(clone $redFlagPos, $blueTee);
    $world->addEntity($character);

    $world->doTick();

    // Red team hears SOUND_CTF_GRAB_EN (enemy grabbed their flag)
    $redSounds = soundsSentToTee($redTee->teeIndex);
    expect($redSounds)->toContain(GameConstants::SOUND_CTF_GRAB_EN);

    // Blue team hears SOUND_CTF_GRAB_PL (we grabbed their flag)
    $blueSounds = soundsSentToTee($blueTee->teeIndex);
    expect($blueSounds)->toContain(GameConstants::SOUND_CTF_GRAB_PL);
});

test('CTF flag capture sends SOUND_CTF_CAPTURE to all tees', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    $blueFlag = $controller->getFlag(GameConstants::TEAM_BLUE);
    assert($redFlag !== null);
    assert($blueFlag !== null);

    $redTee = new PlayerTee; $redTee->name = 'Red'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;
    $blueTee = new PlayerTee; $blueTee->name = 'Blue'; $world->addTee($blueTee); $blueTee->team = GameConstants::TEAM_BLUE;

    // Blue player carrying the red flag, at the blue stand
    $blueFlagPos = $blueFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $blueFlagPos);
    $character->spawn(clone $blueFlagPos, $blueTee);
    $world->addEntity($character);

    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;
    $redFlag->setPosition(clone $blueFlagPos);

    $world->doTick();

    // Both tees should hear SOUND_CTF_CAPTURE
    expect(soundsSentToTee($redTee->teeIndex))->toContain(GameConstants::SOUND_CTF_CAPTURE);
    expect(soundsSentToTee($blueTee->teeIndex))->toContain(GameConstants::SOUND_CTF_CAPTURE);
});

test('CTF flag return sends SOUND_CTF_RETURN to all tees', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $redTee = new PlayerTee; $redTee->name = 'Red'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;
    $blueTee = new PlayerTee; $blueTee->name = 'Blue'; $world->addTee($blueTee); $blueTee->team = GameConstants::TEAM_BLUE;

    // Simulate a dropped red flag
    $redFlag->atStand = false;
    $redFlag->setPosition(new Vector2($redFlag->standPos->x + 100, $redFlag->standPos->y));
    $redFlag->dropTick = 100;

    // Red player at the dropped flag position → returns it
    $redFlagPos = $redFlag->getPosition();
    $character = new PvpCharacterEntity($world, clone $redFlagPos);
    $character->spawn(clone $redFlagPos, $redTee);
    $world->addEntity($character);

    $world->doTick();

    expect(soundsSentToTee($redTee->teeIndex))->toContain(GameConstants::SOUND_CTF_RETURN);
    expect(soundsSentToTee($blueTee->teeIndex))->toContain(GameConstants::SOUND_CTF_RETURN);
});

test('CTF flag drop on death sends SOUND_CTF_DROP to all tees', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $redTee = new PlayerTee; $redTee->name = 'Red'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;
    $blueTee = new PlayerTee; $blueTee->name = 'Blue'; $world->addTee($blueTee); $blueTee->team = GameConstants::TEAM_BLUE;

    $character = new PvpCharacterEntity($world, new Vector2(500, 500));
    $character->spawn(new Vector2(500, 500), $blueTee);
    $world->addEntity($character);

    // Blue player carrying the red flag
    $redFlag->atStand = false;
    $redFlag->carryingCharacter = $character;

    // Kill the carrier
    $character->die(-1, GameConstants::WEAPON_WORLD);

    expect(soundsSentToTee($redTee->teeIndex))->toContain(GameConstants::SOUND_CTF_DROP);
    expect(soundsSentToTee($blueTee->teeIndex))->toContain(GameConstants::SOUND_CTF_DROP);
});

test('CTF flag auto-return after 30 seconds sends SOUND_CTF_RETURN', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redFlag = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);

    $redTee = new PlayerTee; $redTee->name = 'Red'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;

    $redFlag->atStand = false;
    $redFlag->setPosition(new Vector2($redFlag->standPos->x + 100, $redFlag->standPos->y));
    $redFlag->dropTick = 100;

    // Advance 31 seconds
    setCtfTick($tickHandler, 100 + 1551);
    $world->doTick();

    expect(soundsSentToTee($redTee->teeIndex))->toContain(GameConstants::SOUND_CTF_RETURN);
});

/*
|--------------------------------------------------------------------------
| Hit sound (SOUND_HIT)
|--------------------------------------------------------------------------
*/

test('takeDamage sends SOUND_HIT to the attacker only', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $redTee = new PlayerTee; $redTee->name = 'Att'; $world->addTee($redTee); $redTee->team = GameConstants::TEAM_RED;
    $blueTee = new PlayerTee; $blueTee->name = 'Vic'; $world->addTee($blueTee); $blueTee->team = GameConstants::TEAM_BLUE;

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $redTee);
    $world->addEntity($attacker);

    $victim = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $blueTee);
    $world->addEntity($victim);

    $victim->takeDamage(new Vector2(0, 0), 5, $attacker);

    // Attacker should hear SOUND_HIT
    expect(soundsSentToTee($redTee->teeIndex))->toContain(GameConstants::SOUND_HIT);
    // Victim should NOT hear SOUND_HIT
    expect(soundsSentToTee($blueTee->teeIndex))->not()->toContain(GameConstants::SOUND_HIT);
});

test('takeDamage does not send SOUND_HIT on self-damage', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $tee = new PlayerTee; $tee->name = 'Self'; $world->addTee($tee); $tee->team = GameConstants::TEAM_RED;

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);

    $character->takeDamage(new Vector2(0, 0), 4, $character);

    // No SOUND_HIT should be sent for self-damage
    expect(soundsSentToTee($tee->teeIndex))->not()->toContain(GameConstants::SOUND_HIT);
});

test('takeDamage does not send SOUND_HIT on friendly fire (blocked)', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    $red1 = new PlayerTee; $red1->name = 'R1'; $world->addTee($red1); $red1->team = GameConstants::TEAM_RED;
    $red2 = new PlayerTee; $red2->name = 'R2'; $world->addTee($red2); $red2->team = GameConstants::TEAM_RED;

    $spawnPos = new Vector2(50 * 32, 25 * 32);
    $attacker = new PvpCharacterEntity($world, clone $spawnPos);
    $attacker->spawn(clone $spawnPos, $red1);
    $world->addEntity($attacker);

    $victim = new PvpCharacterEntity($world, new Vector2($spawnPos->x + 50, $spawnPos->y));
    $victim->spawn(new Vector2($spawnPos->x + 50, $spawnPos->y), $red2);
    $world->addEntity($victim);

    $victim->takeDamage(new Vector2(0, 0), 5, $attacker);

    // Friendly fire is blocked — no damage, no SOUND_HIT
    expect(soundsSentToTee($red1->teeIndex))->not()->toContain(GameConstants::SOUND_HIT);
});

/*
|--------------------------------------------------------------------------
| Team spawn routing on CTF map
|--------------------------------------------------------------------------
*/

test('red tee spawns at a red spawn point on the CTF map', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    // Collect all red spawn positions from the map
    $gameLayer = $map->getGameLayer();
    assert($gameLayer !== null);
    $redSpawnPositions = [];
    foreach ($gameLayer->getEntityPositions() as $entity) {
        if ($entity['type'] === GameLayer::ENTITY_SPAWN_RED) {
            $redSpawnPositions[] = new Vector2($entity['x'], $entity['y']);
        }
    }
    expect(count($redSpawnPositions))->toBeGreaterThan(0);

    $redTee = new PlayerTee;
    $redTee->name = 'RedSpawn';
    $world->addTee($redTee);
    $redTee->team = GameConstants::TEAM_RED;

    // Tick to trigger respawn
    $world->doTick();

    $character = $redTee->character;
    assert($character instanceof PvpCharacterEntity);
    $spawnPos = $character->getPosition();

    // The character should be at one of the red spawn positions
    $atRedSpawn = false;
    foreach ($redSpawnPositions as $redSpawn) {
        if ((int) round($spawnPos->x) === (int) round($redSpawn->x)
            && (int) round($spawnPos->y) === (int) round($redSpawn->y)) {
            $atRedSpawn = true;
            break;
        }
    }
    expect($atRedSpawn)->toBeTrue();
});

test('blue tee spawns at a blue spawn point on the CTF map', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    // Collect all blue spawn positions from the map
    $gameLayer = $map->getGameLayer();
    assert($gameLayer !== null);
    $blueSpawnPositions = [];
    foreach ($gameLayer->getEntityPositions() as $entity) {
        if ($entity['type'] === GameLayer::ENTITY_SPAWN_BLUE) {
            $blueSpawnPositions[] = new Vector2($entity['x'], $entity['y']);
        }
    }
    expect(count($blueSpawnPositions))->toBeGreaterThan(0);

    $blueTee = new PlayerTee;
    $blueTee->name = 'BlueSpawn';
    $world->addTee($blueTee);
    $blueTee->team = GameConstants::TEAM_BLUE;

    // Tick to trigger respawn
    $world->doTick();

    $character = $blueTee->character;
    assert($character instanceof PvpCharacterEntity);
    $spawnPos = $character->getPosition();

    // The character should be at one of the blue spawn positions
    $atBlueSpawn = false;
    foreach ($blueSpawnPositions as $blueSpawn) {
        if ((int) round($spawnPos->x) === (int) round($blueSpawn->x)
            && (int) round($spawnPos->y) === (int) round($blueSpawn->y)) {
            $atBlueSpawn = true;
            break;
        }
    }
    expect($atBlueSpawn)->toBeTrue();
});

test('red tee does not spawn at a blue spawn point on the CTF map', function () use ($ctfMapPath, $ctfMapExists) {
    if (! $ctfMapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($ctfMapPath);
    $tickHandler = new TickHandler(100);
    $controller = makeCtfGameController($tickHandler);
    $world = createCtfWorld($map, $tickHandler, $controller);

    // Collect all blue spawn positions from the map
    $gameLayer = $map->getGameLayer();
    assert($gameLayer !== null);
    $blueSpawnPositions = [];
    foreach ($gameLayer->getEntityPositions() as $entity) {
        if ($entity['type'] === GameLayer::ENTITY_SPAWN_BLUE) {
            $blueSpawnPositions[] = new Vector2($entity['x'], $entity['y']);
        }
    }

    $redTee = new PlayerTee;
    $redTee->name = 'RedSpawn';
    $world->addTee($redTee);
    $redTee->team = GameConstants::TEAM_RED;

    $world->doTick();

    $character = $redTee->character;
    assert($character instanceof PvpCharacterEntity);
    $spawnPos = $character->getPosition();

    // The character should NOT be at any blue spawn position
    $atBlueSpawn = false;
    foreach ($blueSpawnPositions as $blueSpawn) {
        if ((int) round($spawnPos->x) === (int) round($blueSpawn->x)
            && (int) round($spawnPos->y) === (int) round($blueSpawn->y)) {
            $atBlueSpawn = true;
            break;
        }
    }
    expect($atBlueSpawn)->toBeFalse();
});
