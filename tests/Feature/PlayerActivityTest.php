<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Server\Config\Config;

test('changed aim counts as activity but repeating the same target does not', function (string $state) {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    if ($state === 'living') {
        $character = new PvpCharacterEntity($world, new Vector2(64, 64));
        $character->spawn(new Vector2(64, 64), $tee);
        $world->addEntity($character);
    } elseif ($state === 'spectator') {
        $tee->team = GameConstants::TEAM_SPECTATORS;
    }
    $tee->onDirectInput(input(['targetX' => 100, 'targetY' => 0]), $clock->getCurrentTick());
    $clock->advanceTick();

    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0]), $clock->getCurrentTick());
    expect($tee->lastActionTick)->toBe(101);
    $clock->advanceTick();
    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0]), $clock->getCurrentTick());
    expect($tee->lastActionTick)->toBe(101);
    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 10]), $clock->getCurrentTick());
    expect($tee->lastActionTick)->toBe(102);
})->with(['living', 'dead', 'spectator']);

test('chatting aim changes do not update activity or replace the last active target', function () {
    $tee = new PlayerTee;
    $tee->onDirectInput(input(['targetX' => 100, 'targetY' => 0]), 100);
    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0, 'playerFlags' => GameConstants::PLAYERFLAG_CHATTING]), 101);
    $tee->onDirectInput(input(['targetX' => 300, 'targetY' => 0, 'playerFlags' => GameConstants::PLAYERFLAG_CHATTING]), 102);
    expect($tee->lastActionTick)->toBe(100);

    $tee->onDirectInput(input(['targetX' => 100, 'targetY' => 0]), 103);
    expect($tee->lastActionTick)->toBe(100);
    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0]), 104);
    expect($tee->lastActionTick)->toBe(104);
});

test('mouse movement prevents an inactivity kick but unchanged aim eventually times out', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $world      = new TestWorldWithController($clock, new Map, $controller, config: new Config([
        'sv_world_inactivity_timeout' => 1,
        'sv_world_inactivity_action'  => AbstractGameController::INACTIVE_KICK_KICK,
    ]));
    $tee = new PlayerTee;
    $world->addTee($tee);
    $tee->onDirectInput(input(['targetX' => 100, 'targetY' => 0]), $clock->getCurrentTick());
    for ($i = 0; $i <= NetworkParams::TICKS_PER_SECOND * 60; $i++) {
        $clock->advanceTick();
    }

    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0]), $clock->getCurrentTick());
    $controller->doTick();
    expect(recordingServer($world)->kickedTees)->toBeEmpty();

    for ($i = 0; $i <= NetworkParams::TICKS_PER_SECOND * 60; $i++) {
        $clock->advanceTick();
    }
    $tee->onDirectInput(input(['targetX' => 200, 'targetY' => 0]), $clock->getCurrentTick());
    $controller->doTick();
    expect(recordingServer($world)->kickedTees)->toBe([$tee->teeIndex => 'Kicked for inactivity']);
});
