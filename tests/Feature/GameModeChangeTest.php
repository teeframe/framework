<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\ChangeGameModeCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Server\AbstractServerInstance;

function createGameModeChangeWorld(): TestWorldWithController
{
    $clock  = new TickHandler(1000);
    $server = new class($clock, name: 'Gamemode change test') extends AbstractServerInstance
    {
        protected function boot(): void {}

        protected function selectWorldForNewConnection(): AbstractWorld
        {
            throw new RuntimeException('Not used by this test');
        }
    };
    $controller = new class($clock, scoreLimit: 10, timeLimit: 15, spectatorSlots: 2) extends AbstractGameController {};

    return new TestWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller, $server, slotsLimit: 12);
}

test('gamemode commands are vote-only and identify their target mode consistently', function () {
    $command = new ChangeGameModeCommand('CtF');

    expect($command->getType())->toBe(AbstractCommand::TYPE_VOTE);
    expect($command->getName())->toBe('change_gamemode ctf');
    expect($command->getCommand())->toBe('change_gamemode ctf');
    expect($command->getDescription())->toBe('Change gamemode to CTF');
});

test('gamemode changes are deferred and rebuild round and flag state without replacing the map or configuration', function () {
    $world           = createGameModeChangeWorld();
    $map             = $world->getMap();
    $votes           = $world->getVoteController();
    $tuning          = $world->getTuneController();
    $tuning->gravity = 30;
    $command         = new ChangeGameModeCommand('ctf');
    $world->registerCommand($command);
    $votes->addVoteOption($command->getDescription(), $command->getCommand());

    foreach (['ctf', 'tdm', 'dm', 'CTF'] as $mode) {
        $oldController = $world->getGameController();
        $oldType       = $oldController->getGameType();
        $oldFlags      = $oldController->getFlags();
        $oldController->endRound();
        $world->resetGame();
        $world->changeGameMode($mode);

        expect($world->getGameController())->toBe($oldController);
        expect($oldController->getGameType())->toBe($oldType);
        expect($world->isPaused())->toBeTrue();
        $world->doTick();

        $controller = $world->getGameController();
        expect($controller)->not()->toBe($oldController);
        expect($controller->getWorld())->toBe($world);
        expect($controller->getGameType())->toBe(strtoupper($mode));
        expect($controller->isTeamplay())->toBe(strtoupper($mode) !== 'DM');
        expect($controller->isGameOver())->toBeFalse();
        expect($world->getMap())->toBe($map);
        expect($world->getSlotsLimit())->toBe(12);
        expect($controller->getSpectatorSlots())->toBe(2);
        expect($world->getVoteController())->toBe($votes);
        expect($votes->getVoteChunks(new PlayerTee))->toHaveCount(1);
        expect($world->getCommands())->toContain($command);
        expect($world->getTuneController())->toBe($tuning);
        expect($tuning->gravity)->toBe(30);
        expect($world->getCurrentTick())->toBe(1000);
        expect($world->isPaused())->toBeFalse();
        expect($world->isResetRequested())->toBeFalse();

        foreach ($oldFlags as $flag) {
            if ($flag !== null) {
                expect($flag->isToDestroy())->toBeTrue();
                expect($world->getEntities())->not()->toContain($flag);
            }
        }
        if (strtoupper($mode) === 'CTF') {
            expect($controller->getFlag(GameConstants::TEAM_RED))->not()->toBeNull();
            expect($controller->getFlag(GameConstants::TEAM_BLUE))->not()->toBeNull();
            expect($world->getEntities())->toHaveCount(2);
        } else {
            expect($controller->getFlags())->toBe([null, null]);
            expect($world->getEntities())->toBeEmpty();
        }
        $info = $controller->doSnap(new PlayerTee)[0];
        assert($info instanceof ObjGameInfoItem);
        expect([$info->scoreLimit, $info->timeLimit, $info->roundStartTick, $info->roundCurrent])->toBe([10, 15, 1000, 1]);
    }
});

test('invalid gamemodes do not alter the active controller or queue a reload', function (string $mode) {
    $world      = createGameModeChangeWorld();
    $map        = $world->getMap();
    $controller = $world->getGameController();

    expect(fn () => $world->changeGameMode($mode, 'dm1'))->toThrow(RuntimeException::class, 'Unsupported gamemode');
    $world->doTick();

    expect($world->getGameController())->toBe($controller);
    expect($controller->getGameType())->toBe('DM');
    expect($world->getMap())->toBe($map);
})->with(['', 'unknown', 'ctf ', "ctf\0"]);

test('a failed gamemode map load does not queue either change', function () {
    $world      = createGameModeChangeWorld();
    $map        = $world->getMap();
    $controller = $world->getGameController();

    expect(fn () => $world->changeGameMode('ctf', 'missing-map'))->toThrow(RuntimeException::class);
    $world->doTick();

    expect($world->getGameController())->toBe($controller);
    expect($controller->getGameType())->toBe('DM');
    expect($world->getMap())->toBe($map);
});

test('a queued map selection and gamemode change are applied together', function () {
    $world = createGameModeChangeWorld();
    $world->changeMap('dm1');
    $world->changeGameMode('tdm');
    $world->doTick();

    expect($world->getMap()->getName())->toBe('dm1');
    expect($world->getGameController()->getGameType())->toBe('TDM');

    $world->changeGameMode('ctf', 'ctf1');
    $world->doTick();
    expect($world->getMap()->getName())->toBe('ctf1');
    expect($world->getGameController()->getGameType())->toBe('CTF');
    expect($world->getGameController()->getFlag(GameConstants::TEAM_RED))->not()->toBeNull();
    expect($world->getGameController()->getFlag(GameConstants::TEAM_BLUE))->not()->toBeNull();
});

test('reload combines map and gamemode options and preserves queued changes across requests', function () {
    $world = createGameModeChangeWorld();
    $world->reload(gameMode: 'tdm');
    $world->reload(mapName: 'dm1');
    $world->reload();
    expect($world->getGameController()->getGameType())->toBe('DM');
    expect($world->getMap()->getName())->toBe('ctf1');
    $world->doTick();

    expect($world->getGameController()->getGameType())->toBe('TDM');
    expect($world->getMap()->getName())->toBe('dm1');

    $world->reload(mapName: 'ctf1', gameMode: 'ctf');
    $world->doTick();
    expect($world->getGameController()->getGameType())->toBe('CTF');
    expect($world->getMap()->getName())->toBe('ctf1');
    expect($world->getGameController()->getFlag(GameConstants::TEAM_RED))->not()->toBeNull();
    expect($world->getGameController()->getFlag(GameConstants::TEAM_BLUE))->not()->toBeNull();
});

test('a failed combined reload leaves an already queued reload intact', function (string $mapName, string $gameMode) {
    $world = createGameModeChangeWorld();
    $world->reload(mapName: 'dm1', gameMode: 'tdm');

    expect(fn () => $world->reload(mapName: $mapName, gameMode: $gameMode))->toThrow(RuntimeException::class);
    $world->doTick();

    expect($world->getMap()->getName())->toBe('dm1');
    expect($world->getGameController()->getGameType())->toBe('TDM');
    expect($world->getGameController()->getFlags())->toBe([null, null]);
})->with([
    'invalid map'      => ['missing-map', 'ctf'],
    'invalid gamemode' => ['ctf1', 'unknown'],
]);
