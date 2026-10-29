<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Server\AbstractServerInstance;

class NeutralTestGameController extends AbstractGameController
{
    public function getGameType(): string
    {
        return 'Custom';
    }
}

function createNeutralControllerWorld(TickHandler $clock, int $slotsLimit = 64, int $spectatorSlots = 0, int $inactiveKickTime = 0): TestWorldWithController
{
    $server = new class($clock, name: 'Neutral controller test') extends AbstractServerInstance
    {
        protected function boot(): void {}

        protected function selectWorldForNewConnection(): AbstractWorld
        {
            throw new RuntimeException('Not used by this test');
        }
    };
    $controller = new NeutralTestGameController($clock, inactiveKickTime: $inactiveKickTime, spectatorSlots: $spectatorSlots);

    return new TestWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller, $server, $slotsLimit);
}

test('a base controller boots a map with flag stands without creating flags or team snapshots', function () {
    $world      = createNeutralControllerWorld(new TickHandler(100));
    $controller = $world->getGameController();
    $tee        = new PlayerTee;
    $world->addTee($tee);
    $world->doTick();

    expect($controller->getGameType())->toBe('Custom');
    expect($controller->isTeamplay())->toBeFalse();
    expect($tee->character)->toBeInstanceOf(AbstractCharacterEntity::class);
    expect(array_filter($world->getEntities(), fn ($entity) => $entity instanceof FlagEntity))->toBeEmpty();
    $snaps = $controller->doSnap($tee);
    expect($snaps)->toHaveCount(1);
    expect(array_filter($snaps, fn ($snap) => $snap instanceof ObjGameDataItem))->toBeEmpty();
    $info = $snaps[0];
    assert($info instanceof ObjGameInfoItem);
    expect([$info->gameFlags, $info->gameStateFlags, $info->scoreLimit, $info->timeLimit])->toBe([0, 0, 0, 0]);
});

test('a base controller does not award kill points or deduct suicide points', function () {
    $world  = createNeutralControllerWorld(new TickHandler(100));
    $killer = new PlayerTee;
    $victim = new PlayerTee;
    $world->addTee($killer);
    $world->addTee($victim);
    $world->doTick();
    $killerCharacter = $killer->character;
    $victimCharacter = $victim->character;
    assert($killerCharacter !== null && $victimCharacter !== null);
    $killer->score = 10;
    $victim->score = 20;

    $victimCharacter->die($killer->teeIndex, GameConstants::WEAPON_GUN);
    expect([$killer->score, $victim->score])->toBe([10, 20]);
    $killerCharacter->die($killer->teeIndex, GameConstants::WEAPON_SELF);
    expect([$killer->score, $victim->score])->toBe([10, 20]);
});

test('a base controller has no automatic win condition or round restart', function () {
    $clock = new TickHandler(100);
    $world = createNeutralControllerWorld($clock);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $tee->score = 1000;
    $clock->next();
    $world->doTick();

    expect($tee->score)->toBe(1000);
    expect($world->isPaused())->toBeFalse();
    expect($world->isResetRequested())->toBeFalse();

    $world->setPaused(true);
    for ($i = 0; $i < 501; $i++) {
        $clock->next();
        $world->doTick();
    }

    expect($world->isPaused())->toBeTrue();
    expect($tee->score)->toBe(1000);
    $info = $world->getGameController()->doSnap($tee)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->gameStateFlags)->toBe(GameConstants::GAMESTATEFLAG_PAUSED);
});

test('a base controller rejects PvP mode changes without altering or reloading the world', function (string $mode) {
    $world      = createNeutralControllerWorld(new TickHandler(100));
    $map        = $world->getMap();
    $controller = $world->getGameController();

    expect(fn () => $world->reload(mapName: 'dm1', gameMode: $mode))
        ->toThrow(RuntimeException::class, 'Unsupported gamemode: '.$mode);
    $world->doTick();

    expect($world->getMap())->toBe($map);
    expect($world->getGameController())->toBe($controller);
    expect($controller->getGameType())->toBe('Custom');
})->with(['DM', 'TDM', 'CTF']);

test('a base controller preserves shared player capacity without PvP team balancing or friendly fire', function () {
    $world       = createNeutralControllerWorld(new TickHandler(100), slotsLimit: 3, spectatorSlots: 1);
    $controller  = $world->getGameController();
    $first       = new PlayerTee;
    $second      = new PlayerTee;
    $third       = new PlayerTee;
    $third->team = GameConstants::TEAM_SPECTATORS;
    $world->addTee($first);
    $first->team = GameConstants::TEAM_BLUE;
    $world->addTee($second);
    $second->team = GameConstants::TEAM_BLUE;
    $world->addTee($third);

    expect($third->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($controller->canJoinTeam(GameConstants::TEAM_RED, $third->teeIndex))->toBeFalse();
    expect($controller->canJoinTeam(GameConstants::TEAM_SPECTATORS, $first->teeIndex))->toBeTrue();
    expect($controller->isFriendlyFire($first->teeIndex, $second->teeIndex))->toBeFalse();
    expect($controller->checkTeamBalance())->toBeTrue();
    expect($controller->getTeamName(GameConstants::TEAM_BLUE))->toBe('game');
});

test('a base controller still enforces its inactivity policy', function () {
    $clock = new TickHandler(100);
    $world = createNeutralControllerWorld($clock, inactiveKickTime: 1);
    $tee   = new PlayerTee;
    $world->addTee($tee);

    for ($i = 0; $i < 3001; $i++) {
        $clock->next();
    }
    $world->doTick();

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
});

test('a base controller can reload maps and spawn again without PvP APIs', function () {
    $clock      = new TickHandler(100);
    $world      = createNeutralControllerWorld($clock, slotsLimit: 12, spectatorSlots: 2);
    $controller = $world->getGameController();
    $world->changeMap('dm1');
    $clock->next();
    $world->doTick();

    expect($world->getMap()->getName())->toBe('dm1');
    expect($world->getGameController())->toBe($controller);
    expect($controller->getSpectatorSlots())->toBe(2);
    expect($controller->getGameType())->toBe('Custom');
    $tee = new PlayerTee;
    $world->addTee($tee);
    $world->doTick();
    expect($tee->character)->toBeInstanceOf(AbstractCharacterEntity::class);
    $info = $controller->doSnap($tee)[0];
    assert($info instanceof ObjGameInfoItem);
    expect($info->roundStartTick)->toBe(101);
    expect($info->gameFlags)->toBe(0);
});

test('PvP controllers retain their mode snapshots and kill and suicide scoring', function (bool $teams, bool $ctf, string $mode, int $gameFlags) {
    resetMockServer();
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock, isTeamMode: $teams, isCaptureTheFlag: $ctf, scoreLimit: 20, timeLimit: 10);
    $world      = new TestPvpWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller);
    $killer     = new PlayerTee;
    $victim     = new PlayerTee;
    $world->addTee($killer);
    $world->addTee($victim);
    $world->doTick();
    $killerCharacter = $killer->character;
    $victimCharacter = $victim->character;
    assert($killerCharacter !== null && $victimCharacter !== null);

    $victimCharacter->die($killer->teeIndex, GameConstants::WEAPON_GUN);
    expect([$killer->score, $victim->score])->toBe([1, 0]);
    $killerCharacter->die($killer->teeIndex, GameConstants::WEAPON_SELF);
    expect([$killer->score, $victim->score])->toBe([0, 0]);
    expect($controller->getGameType())->toBe($mode);
    $snaps = $controller->doSnap($killer);
    expect($snaps)->toHaveCount($teams ? 2 : 1);
    $info = $snaps[0];
    assert($info instanceof ObjGameInfoItem);
    expect([$info->gameFlags, $info->scoreLimit, $info->timeLimit])->toBe([$gameFlags, 20, 10]);
})->with([
    'DM'  => [false, false, 'DM', 0],
    'TDM' => [true, false, 'TDM', GameConstants::GAMEFLAG_TEAMS],
    'CTF' => [true, true, 'CTF', GameConstants::GAMEFLAG_TEAMS | GameConstants::GAMEFLAG_FLAGS],
]);

test('only PvP team modes prioritize their own team spawn points', function () {
    $clock   = new TickHandler(100);
    $world   = createNeutralControllerWorld($clock);
    $neutral = $world->getGameController();
    expect($neutral->canSpawn($world, GameConstants::TEAM_RED))
        ->toEqual($neutral->canSpawn($world, GameConstants::TEAM_BLUE));
    expect($neutral->canSpawn($world, GameConstants::TEAM_SPECTATORS))->toBeNull();

    $controller = new PvpGameController($clock, isTeamMode: true);
    $pvpWorld   = new TestPvpWorldWithController($clock, $world->getMap(), $controller);
    $layer      = $world->getMap()->getGameLayer();
    assert($layer !== null);
    foreach ([GameConstants::TEAM_RED => GameLayer::ENTITY_SPAWN_RED, GameConstants::TEAM_BLUE => GameLayer::ENTITY_SPAWN_BLUE] as $team => $type) {
        $spawn = $controller->canSpawn($pvpWorld, $team);
        assert($spawn !== null);
        $teamSpawns = array_filter($layer->getEntityPositions(), fn ($entity) => $entity['type'] === $type);
        $positions  = array_map(fn ($entity) => [(float) $entity['x'], (float) $entity['y']], $teamSpawns);
        expect($positions)->toContain([$spawn->x, $spawn->y]);
    }
});
