<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\ChangeMapCommand;
use TeeFrame\Game\Commands\ReloadCommand;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\PickupSpawner;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Server\AbstractServerInstance;

function createMapChangeWorld(TickHandler $tickHandler, ?Map $map = null): TestPvpWorldWithController
{
    $server = new class($tickHandler, name: 'Map change test') extends AbstractServerInstance
    {
        protected function boot(): void {}

        protected function selectWorldForNewConnection(): AbstractWorld
        {
            throw new RuntimeException('Not used by this test');
        }
    };
    $controller = new PvpGameController($tickHandler, isTeamMode: true, isCaptureTheFlag: true, scoreLimit: 300, timeLimit: 10, spectatorSlots: 2);

    return new class($tickHandler, $map ?? new Map(__DIR__.'/../ctf1.map'), $controller, $server, slotsLimit: 12) extends TestPvpWorldWithController
    {
        protected function bootMap(): void
        {
            parent::bootMap();

            $gameLayer = $this->getMap()->getGameLayer();
            assert($gameLayer !== null);
            PickupSpawner::spawn($this, $gameLayer, [
                GameLayer::ENTITY_HEALTH_1      => 1750,
                GameLayer::ENTITY_POWERUP_NINJA => 3000,
            ], [GameLayer::ENTITY_POWERUP_NINJA => 4500]);
        }
    };
}

test('map reload replaces entities spawns flags and round state while preserving world configuration', function () {
    $tickHandler     = new TickHandler(1000);
    $world           = createMapChangeWorld($tickHandler);
    $controller      = $world->getGameController();
    $votes           = $world->getVoteController();
    $tuning          = $world->getTuneController();
    $tuning->gravity = 30;
    $command         = new ChangeMapCommand('dm1');
    $world->registerCommand($command);
    $votes->addVoteOption($command->getDescription(), $command->getCommand());
    $oldMap      = $world->getMap();
    $oldEntities = $world->getEntities();
    $oldPool     = (new ReflectionClass($world))->getProperty('snapIdPool')->getValue($world);
    $world->addEvent(new ObjEventSoundWorldItem(0, 0, 1), excludedTeeIndex: 0);
    $controller->endRound();
    $world->resetGame();

    $world->changeMap('dm1');
    expect($world->getMap())->toBe($oldMap);
    expect($world->isPaused())->toBeTrue();
    $world->doTick();

    expect($world->getMap()->getName())->toBe('dm1');
    expect($world->getMap())->not()->toBe($oldMap);
    expect($world->getMap()->getPath())->toBe(realpath(__DIR__.'/../dm1.map'));
    expect($world->getGameController())->toBe($controller);
    expect($world->getGameController()->getGameType())->toBe('CTF');
    expect($world->getSlotsLimit())->toBe(12);
    expect($controller->getSpectatorSlots())->toBe(2);
    expect($world->getVoteController())->toBe($votes);
    expect($votes->getVoteChunks(new PlayerTee))->toHaveCount(1);
    expect($world->getCommands())->toContain($command);
    expect($world->getTuneController())->toBe($tuning);
    expect($tuning->gravity)->toBe(30);
    expect($world->isPaused())->toBeFalse();
    expect($world->isResetRequested())->toBeFalse();
    expect($world->getCurrentTick())->toBe(1000);
    expect($controller->isGameOver())->toBeFalse();
    expect($controller->getFlags())->toBe([null, null]);
    expect($world->getTees())->toBeEmpty();
    foreach ($oldEntities as $entity) {
        expect($entity->isToDestroy())->toBeTrue();
        expect($world->getEntities())->not()->toContain($entity);
    }
    $reflection = new ReflectionClass($world);
    expect($reflection->getProperty('snapIdPool')->getValue($world))->not()->toBe($oldPool);
    expect($reflection->getProperty('pendingEvents')->getValue($world))->toBeEmpty();
    expect($reflection->getProperty('excludedEventTeeIndexes')->getValue($world))->toBeEmpty();

    $expectedController = new PvpGameController($tickHandler);
    $gameLayer          = $world->getMap()->getGameLayer();
    assert($gameLayer !== null);
    $expectedController->collectSpawnPoints($gameLayer);
    $spawns = (new ReflectionClass($controller))->getProperty('spawnPoints');
    expect($spawns->getValue($controller))->toEqual($spawns->getValue($expectedController));
    $info = $controller->doSnap(new PlayerTee)[0];
    assert($info instanceof ObjGameInfoItem);
    expect([$info->scoreLimit, $info->timeLimit, $info->roundStartTick, $info->roundCurrent])->toBe([300, 10, 1000, 1]);

    $ninjas = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PickupEntity
        && (new ReflectionClass($entity))->getProperty('type')->getValue($entity) === GameConstants::POWERUP_NINJA));
    expect($ninjas)->toHaveCount(1);
    $pickupReflection = new ReflectionClass($ninjas[0]);
    expect($pickupReflection->getProperty('spawnTick')->getValue($ninjas[0]))->toBe(5500);
    expect($pickupReflection->getProperty('respawnTime')->getValue($ninjas[0]))->toBe(3000);

    $world->changeMap('ctf1');
    $world->doTick();
    expect($controller->getFlag(GameConstants::TEAM_RED))->not()->toBeNull();
    expect($controller->getFlag(GameConstants::TEAM_BLUE))->not()->toBeNull();
    expect($world->getEntities())->toHaveCount(count($oldEntities));
    $world->changeMap('ctf1');
    $world->doTick();
    expect($world->getEntities())->toHaveCount(count($oldEntities));
});

test('map changes reject invalid names without altering the active world', function (string $name) {
    $world    = createMapChangeWorld(new TickHandler(1000));
    $map      = $world->getMap();
    $entities = $world->getEntities();

    expect(fn () => $world->changeMap($name))->toThrow(RuntimeException::class);
    $world->doTick();

    expect($world->getMap())->toBe($map);
    expect($world->getEntities())->toBe($entities);
})->with(['', '.', '..', '../dm1', 'nested/dm1', '..\\dm1', '/dm1', "dm1\0ctf1", 'missing-map']);

test('corrupt maps and datafiles without a game layer leave the running map intact', function (string $bytes) {
    $directory = sys_get_temp_dir().'/teeframe-map-change-'.bin2hex(random_bytes(8));
    mkdir($directory);
    copy(__DIR__.'/../ctf1.map', $directory.'/ctf1.map');
    file_put_contents($directory.'/invalid.map', $bytes);

    try {
        $world    = createMapChangeWorld(new TickHandler(1000), new Map($directory.'/ctf1.map'));
        $map      = $world->getMap();
        $entities = $world->getEntities();

        expect(fn () => $world->changeMap('invalid'))->toThrow(RuntimeException::class);
        $world->doTick();
        expect($world->getMap())->toBe($map);
        expect($world->getEntities())->toBe($entities);
    } finally {
        unlink($directory.'/ctf1.map');
        unlink($directory.'/invalid.map');
        rmdir($directory);
    }
})->with([
    'corrupt map'   => ['not a map'],
    'no game layer' => ['DATA'.pack('V*', 4, 20, 20, 0, 0, 0, 0, 0)],
]);

test('a plain reload re-reads the current map file and a failed reload keeps the running world intact', function () {
    $directory = sys_get_temp_dir().'/teeframe-reload-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $path = $directory.'/current.map';
    copy(__DIR__.'/../ctf1.map', $path);

    try {
        $world       = createMapChangeWorld(new TickHandler(1000), new Map($path));
        $controller  = $world->getGameController();
        $oldMap      = $world->getMap();
        $oldEntities = $world->getEntities();
        $controller->endRound();
        copy(__DIR__.'/../dm1.map', $path);
        $expected = new Map(__DIR__.'/../dm1.map');

        $world->reload();
        expect($world->getMap())->toBe($oldMap);
        expect($world->isPaused())->toBeTrue();
        $world->doTick();

        $map = $world->getMap();
        expect($map)->not()->toBe($oldMap);
        expect($map->getName())->toBe('current');
        expect($map->getPath())->toBe($path);
        expect($map->getCrc())->toBe($expected->getCrc());
        expect($map->getSize())->toBe($expected->getSize());
        expect($map->getRawData())->toBe($expected->getRawData());
        expect($world->getGameController())->toBe($controller);
        expect($controller->getGameType())->toBe('CTF');
        expect($controller->getFlags())->toBe([null, null]);
        expect($controller->isGameOver())->toBeFalse();
        expect($world->isPaused())->toBeFalse();
        expect($world->getCurrentTick())->toBe(1000);
        foreach ($oldEntities as $entity) {
            expect($entity->isToDestroy())->toBeTrue();
            expect($world->getEntities())->not()->toContain($entity);
        }

        $entities = $world->getEntities();
        file_put_contents($path, 'invalid map');
        expect(fn () => $world->reload())->toThrow(RuntimeException::class);
        $world->doTick();
        expect($world->getMap())->toBe($map);
        expect($world->getEntities())->toBe($entities);
        expect($world->getGameController())->toBe($controller);
    } finally {
        unlink($path);
        rmdir($directory);
    }
});

test('reload commands are vote-only and expose the reload vote option', function () {
    $command = new ReloadCommand;

    expect($command->getName())->toBe('reload');
    expect($command->getCommand())->toBe('reload');
    expect($command->getDescription())->toBe('Reload');
    expect($command->getType())->toBe(AbstractCommand::TYPE_VOTE);
});

test('reload commands report a missing map without resetting the running world', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-reload-');
    assert(is_string($path));
    try {
        copy(__DIR__.'/../dm1.map', $path);
        $map       = new Map($path);
        $world     = new TestWorld(new TickHandler(1000), $map);
        $tee       = new PlayerTee;
        $tee->name = 'Voter';
        $world->addTee($tee);
        resetMockServer();
        unlink($path);

        (new ReloadCommand)->execute($world, $tee, []);
        $world->doTick();

        expect($world->getMap())->toBe($map);
        expect($world->getTees())->toBe([$tee]);
        $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($chunk) => $chunk instanceof SvChatChunk);
        expect(array_map(fn ($chunk) => $chunk->text, $chats))->toContain('Unable to reload world');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
