<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\Server\ConfigCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Config\Config;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

class ReloadStateTestController extends PvpGameController
{
    /** @var array<string, int|string> */
    public array $configChanges = [];

    public function onConfigChanged(string $name, int|string $value): void
    {
        parent::onConfigChanged($name, $value);
        $this->configChanges[$name] = $value;
    }
}

function createGameModeChangeWorld(): TestPvpWorldWithController
{
    $clock  = new TickHandler(1000);
    $server = new class($clock, new Config(['sv_name' => 'Gamemode change test', 'sv_world_pvp_score_limit' => 10, 'sv_world_pvp_time_limit' => 15]), activatePvpSettings: true) extends AbstractServerInstance
    {
        protected function boot(): void {}

        protected function selectWorldForNewConnection(): AbstractWorld
        {
            throw new RuntimeException('Not used by this test');
        }
    };
    $controller = new class($clock) extends PvpGameController {};

    return new TestPvpWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller, $server, slotsLimit: 12, spectatorSlots: 2);
}

test('gametype configuration commands expose their description and optional value', function () {
    $world   = createGameModeChangeWorld();
    $command = $world->findCommand('sv_world_game_type', AbstractCommand::TYPE_SERVER);

    expect($command)->toBeInstanceOf(ConfigCommand::class);
    assert($command !== null);
    expect($command->getDescription())->toBe('Game type supported by this world\'s controller');
    expect($command->getParams())->toBe('?r[value]');

});

test('gamemode changes are deferred and rebuild round and flag state without replacing the map or configuration', function () {
    $world           = createGameModeChangeWorld();
    $map             = $world->getMap();
    $votes           = $world->getVoteController();
    $tuning          = $world->getTuneController();
    $tuning->gravity = 30;
    $command         = $world->findCommand('sv_world_game_type', AbstractCommand::TYPE_SERVER);
    assert($command !== null);
    $votes->addVoteOption('Change gamemode to CTF', 'sv_world_game_type ctf');

    foreach (['ctf', 'tdm', 'dm', 'CTF'] as $mode) {
        $oldController = $world->getGameController();
        $oldType       = $oldController->getGameType();
        $oldFlags      = $oldController->getFlags();
        $oldController->endRound();
        $world->requestRoundReset();
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
        expect($world->getSpectatorSlots())->toBe(2);
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
                expect($flag->isMarkedForDestruction())->toBeTrue();
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

test('queued gamemode changes retain subsequent RCON team-lock changes', function (bool $initiallyLocked) {
    $server                  = new RconTestServer;
    $world                   = $server->entryWorld;
    $controller              = $world->getGameController();
    $controller->teamsLocked = $initiallyLocked;
    $world->registerCommand(new RconTestCommand('lock_teams', handler: function (AbstractWorld $world): void {
        $controller              = $world->getGameController();
        $controller->teamsLocked = ! $controller->teamsLocked;
    }));
    $admin = $server->addClient();
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));

    $server->send($admin, new RconCmdChunk('sv_world_game_type tdm; lock_teams'));
    expect($world->getGameController())->toBe($controller);
    expect($controller->getGameType())->toBe('DM');
    expect($controller->teamsLocked)->toBe(! $initiallyLocked);

    $server->tick();

    expect($world->getGameController())->not->toBe($controller);
    expect($world->getGameController()->getGameType())->toBe('TDM');
    expect($world->getGameController()->teamsLocked)->toBe(! $initiallyLocked);
})->with([false, true]);

test('queued mode and map changes preserve later custom controller callbacks', function () {
    $clock      = new TickHandler(100);
    $controller = new ReloadStateTestController($clock);
    $world      = new TestWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller);
    $world->changeGameMode('ctf');
    $world->setConfig('sv_world_welcome', 'First update');
    $world->changeMap('dm1');
    $world->changeGameMode('tdm');
    $world->setConfig('sv_world_welcome', 'Latest update');
    $world->doTick();

    $current = $world->getGameController();
    expect($current)->toBeInstanceOf(ReloadStateTestController::class);
    assert($current instanceof ReloadStateTestController);
    expect($current)->not->toBe($controller);
    expect($current->getGameType())->toBe('TDM');
    expect($world->getMap()->getName())->toBe('dm1');
    expect($current->configChanges)->toBe(['sv_world_welcome' => 'Latest update']);
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
