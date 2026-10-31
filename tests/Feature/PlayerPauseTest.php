<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;

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
