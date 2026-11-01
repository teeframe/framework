<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;

/** @return array{TestPvpWorldWithController, list<PlayerTee>} */
function teamBalancePauseFixture(TickHandler $clock, string $mode = 'TDM'): array
{
    $map = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(32, 16, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 32 * 16));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $controller = new PvpGameController($clock, teamBalanceTime: 1);
    $controller->setGameMode($mode);
    $world                               = new TestPvpWorldWithController($clock, $map, $controller);
    $world->getTuneController()->gravity = 0;
    $players                             = [];
    foreach ([GameConstants::TEAM_RED, GameConstants::TEAM_RED, GameConstants::TEAM_RED, GameConstants::TEAM_BLUE] as $i => $team) {
        $tee = new PlayerTee;
        $world->addTee($tee);
        $tee->team = $team;
        $position  = new Vector2(128 + $i * 96, 128);
        $character = new PvpCharacterEntity($world, $position);
        $character->spawn($position, $tee);
        $world->addEntity($character);
        $players[] = $tee;
    }
    expect($controller->checkTeamBalance())->toBeFalse();

    return [$world, $players];
}

test('paused ticks preserve player deadlines for living dead and spectator players', function (string $state) {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    if ($state === 'living') {
        $character = new PvpCharacterEntity($world, new Vector2(128, 96));
        $character->spawn(new Vector2(128, 96), $tee);
        $world->addEntity($character);
    } elseif ($state === 'spectator') {
        $tee->team = GameConstants::TEAM_SPECTATORS;
    }
    $tee->respawnTick          = 125;
    $tee->dieTick              = 90;
    $tee->scoreStartTick       = 80;
    $tee->lastActionTick       = 70;
    $tee->teamChangeTick       = 140;
    $tee->lastChatTick         = 60;
    $tee->lastKillTick         = 65;
    $tee->lastSetTeam          = 75;
    $tee->lastSetSpectatorMode = 85;
    $tee->lastVoteTry          = 95;
    $tee->lastVoteCall         = 96;
    $world->setPaused(true);

    for ($i = 0; $i < 20; $i++) {
        $world->doTick();
        $clock->next();
    }

    expect([$tee->respawnTick, $tee->dieTick, $tee->scoreStartTick, $tee->lastActionTick, $tee->teamChangeTick])
        ->toBe([145, 110, 100, 90, 160]);
    expect([$tee->lastChatTick, $tee->lastKillTick, $tee->lastSetTeam, $tee->lastSetSpectatorMode, $tee->lastVoteTry, $tee->lastVoteCall])
        ->toBe([60, 65, 75, 85, 95, 96]);
    expect($tee->character !== null)->toBe($state === 'living');
})->with(['living', 'dead', 'spectator']);

test('paused time never triggers inactivity removal and the remaining timeout resumes afterwards', function (int $mode) {
    $clock      = new TickHandler(5000);
    $controller = new PvpGameController($clock, inactiveKickTime: 1, inactiveKick: $mode);
    $world      = new TestWorldWithController($clock, new Map, $controller);
    $tee        = new PlayerTee;
    $world->addTee($tee);
    $tee->lastActionTick = 2001;
    $world->setPaused(true);

    for ($i = 0; $i < 6000; $i++) {
        $world->doTick();
        $clock->next();
    }

    expect($tee->team)->toBe(GameConstants::TEAM_RED);
    expect(recordingServer($world)->kickedTees)->toBeEmpty();
    expect($tee->lastActionTick)->toBe(8001);

    $world->setPaused(false);
    for ($i = 0; $i < 2; $i++) {
        $world->doTick();
        $clock->next();
        expect($tee->team)->toBe(GameConstants::TEAM_RED);
        expect(recordingServer($world)->kickedTees)->toBeEmpty();
    }
    $world->doTick();

    if ($mode === AbstractGameController::INACTIVE_KICK_TO_SPECTATOR) {
        expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    } else {
        expect(recordingServer($world)->kickedTees)->toBe([$tee->teeIndex => 'Kicked for inactivity']);
    }
})->with([
    AbstractGameController::INACTIVE_KICK_TO_SPECTATOR,
    AbstractGameController::INACTIVE_KICK_SPEC_OR_KICK,
    AbstractGameController::INACTIVE_KICK_KICK,
]);

test('team balancing preserves living tees and its remaining delay during manual and post-match pauses', function (string $mode, bool $gameOver) {
    $clock             = new TickHandler(5000);
    [$world, $players] = teamBalancePauseFixture($clock, $mode);
    $controller        = $world->getGameController();
    $balanceStart      = new ReflectionProperty($controller, 'unbalancedTick');
    $startedTick       = $clock->get() - NetworkParams::TICKS_PER_SECOND * 60 + 1;
    $balanceStart->setValue($controller, $startedTick);
    if ($gameOver) {
        $controller->endRound();
    } else {
        $controller->togglePause();
    }
    recordingServer($world)->clear();
    $pauseTicks = 4 * NetworkParams::TICKS_PER_SECOND;

    for ($i = 0; $i < $pauseTicks; $i++) {
        $world->doTick();
        $clock->next();
    }

    expect($world->isPaused())->toBeTrue();
    expect($controller->isGameOver())->toBe($gameOver);
    expect(array_map(fn (PlayerTee $tee) => $tee->team, $players))->toBe([0, 0, 0, 1]);
    foreach ($players as $tee) {
        expect($tee->character)->not->toBeNull();
        expect($tee->character?->alive)->toBeTrue();
        expect($tee->forceBalanced)->toBeFalse();
    }
    expect(recordingServer($world)->sentChunks)->toBeEmpty();
    expect($balanceStart->getValue($controller))->toBe($startedTick + $pauseTicks);

    if ($gameOver) {
        $controller->startRound();
    } else {
        $controller->togglePause();
    }
    for ($i = 0; $i < 2; $i++) {
        $world->doTick();
        $clock->next();
        expect(array_map(fn (PlayerTee $tee) => $tee->team, $players))->toBe([0, 0, 0, 1]);
    }
    $world->doTick();
    expect(array_count_values(array_map(fn (PlayerTee $tee) => $tee->team, $players)))->toEqual([0 => 2, 1 => 2]);
    expect(count(array_filter($players, fn (PlayerTee $tee) => $tee->forceBalanced)))->toBe(1);
    expect($balanceStart->getValue($controller))->toBe(-1);
})->with(['TDM', 'CTF'])->with([false, true]);

test('an overdue balance waits through game over and runs on the automatic round restart tick', function () {
    $clock             = new TickHandler(5000);
    [$world, $players] = teamBalancePauseFixture($clock);
    $controller        = $world->getGameController();
    $balanceStart      = new ReflectionProperty($controller, 'unbalancedTick');
    $balanceStart->setValue($controller, $clock->get() - NetworkParams::TICKS_PER_SECOND * 60 - 1);
    $controller->endRound();

    for ($i = 0; $i <= NetworkParams::TICKS_PER_SECOND * 10; $i++) {
        $world->doTick();
        $clock->next();
    }
    expect($world->isPaused())->toBeTrue();
    expect($controller->isGameOver())->toBeTrue();
    expect(array_map(fn (PlayerTee $tee) => $tee->team, $players))->toBe([0, 0, 0, 1]);
    foreach ($players as $tee) {
        expect($tee->character?->alive)->toBeTrue();
    }

    $world->doTick();

    expect($world->isPaused())->toBeFalse();
    expect($controller->isGameOver())->toBeFalse();
    expect(array_count_values(array_map(fn (PlayerTee $tee) => $tee->team, $players)))->toEqual([0 => 2, 1 => 2]);
    expect($balanceStart->getValue($controller))->toBe(-1);
});

test('pauses do not create a pending team balance when teams are already balanced', function () {
    $clock             = new TickHandler(5000);
    [$world, $players] = teamBalancePauseFixture($clock);
    $players[0]->team  = GameConstants::TEAM_BLUE;
    $controller        = $world->getGameController();
    expect($controller->checkTeamBalance())->toBeTrue();
    $controller->togglePause();

    for ($i = 0; $i < 4 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $world->doTick();
        $clock->next();
    }

    expect((new ReflectionProperty($controller, 'unbalancedTick'))->getValue($controller))->toBe(-1);
    foreach ($players as $tee) {
        expect($tee->character?->alive)->toBeTrue();
        expect($tee->forceBalanced)->toBeFalse();
    }
});

test('pausing one world does not delay another worlds team balancing', function () {
    $clock                         = new TickHandler(5000);
    [$pausedWorld, $pausedPlayers] = teamBalancePauseFixture($clock);
    [$activeWorld, $activePlayers] = teamBalancePauseFixture($clock);
    $startedTick                   = $clock->get() - NetworkParams::TICKS_PER_SECOND * 60 + 1;
    foreach ([$pausedWorld, $activeWorld] as $world) {
        (new ReflectionProperty($world->getGameController(), 'unbalancedTick'))->setValue($world->getGameController(), $startedTick);
    }
    $pausedWorld->setPaused(true);

    for ($i = 0; $i < 3; $i++) {
        $pausedWorld->doTick();
        $activeWorld->doTick();
        $clock->next();
    }

    expect(array_map(fn (PlayerTee $tee) => $tee->team, $pausedPlayers))->toBe([0, 0, 0, 1]);
    expect(array_count_values(array_map(fn (PlayerTee $tee) => $tee->team, $activePlayers)))->toEqual([0 => 2, 1 => 2]);
    foreach ($pausedPlayers as $tee) {
        expect($tee->character?->alive)->toBeTrue();
    }
    expect((new ReflectionProperty($pausedWorld->getGameController(), 'unbalancedTick'))->getValue($pausedWorld->getGameController()))->toBe($startedTick + 3);
    expect((new ReflectionProperty($activeWorld->getGameController(), 'unbalancedTick'))->getValue($activeWorld->getGameController()))->toBe(-1);
});

test('death respawn delays retain their remaining ticks across a pause', function (int $weapon, int $delay) {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map(__DIR__.'/../dm1.map'));
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $world->doTick();
    $clock->next();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);
    $character->die($tee->teeIndex, $weapon);
    $world->setPaused(true);

    for ($i = 0; $i < 200; $i++) {
        $world->doTick();
        $clock->next();
        expect($tee->character)->toBeNull();
    }
    expect($tee->respawnTick - $clock->get())->toBe($delay);

    $world->setPaused(false);
    for ($i = 0; $i < $delay; $i++) {
        $world->doTick();
        $clock->next();
        expect($tee->character)->toBeNull();
    }
    $world->doTick();

    expect($tee->character)->toBeInstanceOf(PvpCharacterEntity::class);
    expect($tee->character->alive)->toBeTrue();
})->with([
    'ordinary death'    => [GameConstants::WEAPON_WORLD, 25],
    'self-kill penalty' => [GameConstants::WEAPON_SELF, 150],
]);

test('pausing one world does not freeze another worlds player timers', function () {
    $clock       = new TickHandler(100);
    $pausedWorld = new TestWorld($clock, new Map);
    $activeWorld = new TestWorld($clock, new Map);
    $pausedTee   = new PlayerTee;
    $activeTee   = new PlayerTee;
    $pausedWorld->addTee($pausedTee);
    $activeWorld->addTee($activeTee);
    $pausedWorld->setPaused(true);

    for ($i = 0; $i < 20; $i++) {
        $pausedWorld->doTick();
        $activeWorld->doTick();
        $clock->next();
    }

    expect([$pausedTee->lastActionTick, $pausedTee->scoreStartTick])->toBe([120, 120]);
    expect([$activeTee->lastActionTick, $activeTee->scoreStartTick])->toBe([100, 100]);
});
