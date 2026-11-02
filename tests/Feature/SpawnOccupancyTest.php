<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;

/** @param list<array{int, int}> $solidTiles */
function createSpawnOccupancyWorld(array $solidTiles = []): TestWorld
{
    $map = new class($solidTiles) extends Map
    {
        /** @param list<array{int, int}> $solidTiles */
        public function __construct(array $solidTiles)
        {
            $tiles = '';
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 24; $x++) {
                    $tiles .= pack('C4', in_array([$x, $y], $solidTiles, true) ? GameLayer::TILE_SOLID : GameLayer::TILE_AIR, 0, 0, 0);
                }
            }
            $this->gameLayer = new GameLayer(24, 16, $tiles);
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $world = new TestWorld(new TickHandler(100), $map);
    (new ReflectionProperty(AbstractGameController::class, 'spawnPoints'))->setValue($world->getGameController(), [
        AbstractGameController::SPAWN_HUMAN => [new Vector2(144, 144)],
        AbstractGameController::SPAWN_RED   => [],
        AbstractGameController::SPAWN_BLUE  => [],
    ]);

    return $world;
}

function addSpawnBlocker(TestWorld $world, Vector2 $position): PvpCharacterEntity
{
    $character = new class($world, $position) extends PvpCharacterEntity
    {
        public function doTick(): void {}
    };
    $character->spawn($position);
    $world->addEntity($character);

    return $character;
}

test('spawn selection tries center left up right and down before leaving spawning pending', function (int $blocked, ?array $expected) {
    $world     = createSpawnOccupancyWorld();
    $positions = [[144, 144], [112, 144], [144, 112], [176, 144], [144, 176]];
    foreach (array_slice($positions, 0, $blocked) as [$x, $y]) {
        addSpawnBlocker($world, new Vector2($x, $y));
    }

    $spawn = $world->getGameController()->selectSpawnPosition($world, GameConstants::TEAM_RED);

    expect($spawn === null ? null : [$spawn->x, $spawn->y])->toBe($expected);
})->with([
    'center'       => [0, [144.0, 144.0]],
    'left'         => [1, [112.0, 144.0]],
    'up'           => [2, [144.0, 112.0]],
    'right'        => [3, [176.0, 144.0]],
    'down'         => [4, [144.0, 176.0]],
    'all occupied' => [5, null],
]);

test('spawn occupancy includes the character proximity radius boundary', function (float $distance, float $expectedX) {
    $world = createSpawnOccupancyWorld();
    addSpawnBlocker($world, new Vector2(144 + $distance, 144));

    $spawn = $world->getGameController()->selectSpawnPosition($world, GameConstants::TEAM_RED);

    expect($spawn)->not->toBeNull();
    assert($spawn !== null);
    expect([$spawn->x, $spawn->y])->toBe([$expectedX, 144.0]);
})->with([
    'touching the radius' => [28.0, 112.0],
    'outside the radius'  => [28.01, 144.0],
]);

test('spawn offsets cannot pass through solid tiles', function (array $solidTiles, ?array $expected) {
    /** @var list<array{int, int}> $solidTiles */
    $world = createSpawnOccupancyWorld($solidTiles);
    addSpawnBlocker($world, new Vector2(144, 144));

    $spawn = $world->getGameController()->selectSpawnPosition($world, GameConstants::TEAM_RED);

    expect($spawn === null ? null : [$spawn->x, $spawn->y])->toBe($expected);
})->with([
    'left blocked'        => [[[3, 4]], [144.0, 112.0]],
    'left and up blocked' => [[[3, 4], [4, 3]], [176.0, 144.0]],
    'only down free'      => [[[3, 4], [4, 3], [5, 4]], [144.0, 176.0]],
    'all offsets blocked' => [[[3, 4], [4, 3], [5, 4], [4, 5]], null],
]);

test('solid spawn positions are rejected even without nearby characters', function () {
    $world = createSpawnOccupancyWorld([[4, 4]]);

    $spawn = $world->getGameController()->selectSpawnPosition($world, GameConstants::TEAM_RED);

    assert($spawn !== null);
    expect([$spawn->x, $spawn->y])->toBe([112.0, 144.0]);
});

test('dead and destroyed characters do not block spawning', function (bool $destroyed) {
    $world     = createSpawnOccupancyWorld();
    $character = addSpawnBlocker($world, new Vector2(144, 144));
    if ($destroyed) {
        $character->markForDestruction();
    } else {
        $character->alive = false;
    }

    $spawn = $world->getGameController()->selectSpawnPosition($world, GameConstants::TEAM_RED);

    assert($spawn !== null);
    expect([$spawn->x, $spawn->y])->toBe([144.0, 144.0]);
})->with([false, true]);

test('spawn scoring ignores spectator cameras and players without a living character', function (string $state) {
    $world      = createSpawnOccupancyWorld();
    $controller = $world->getGameController();
    (new ReflectionProperty(AbstractGameController::class, 'spawnPoints'))->setValue($controller, [
        AbstractGameController::SPAWN_HUMAN => [new Vector2(100, 144), new Vector2(650, 144)],
        AbstractGameController::SPAWN_RED   => [],
        AbstractGameController::SPAWN_BLUE  => [],
    ]);
    $enemy = new PlayerTee;
    $world->addTee($enemy);
    $character           = addSpawnBlocker($world, new Vector2(550, 144));
    $character->tee      = $enemy;
    $enemy->character    = $character;
    $enemy->viewPosition = new Vector2(550, 144);
    expect($controller->selectSpawnPosition($world, GameConstants::TEAM_RED)?->x)->toBe(100.0);

    $observer = new PlayerTee;
    $world->addTee($observer);
    $observer->viewPosition = new Vector2(100, 144);
    if ($state === 'spectator') {
        $observer->team = GameConstants::TEAM_SPECTATORS;
    } elseif ($state !== 'no character') {
        $dead                = addSpawnBlocker($world, new Vector2(100, 144));
        $dead->tee           = $observer;
        $observer->character = $dead;
        if ($state === 'destroyed') {
            $dead->markForDestruction();
        } else {
            $dead->alive = false;
        }
    }

    expect($controller->selectSpawnPosition($world, GameConstants::TEAM_RED)?->x)->toBe(100.0);
})->with(['spectator', 'no character', 'dead', 'destroyed']);

test('spawn scoring uses a living characters position instead of its camera position', function () {
    $world      = createSpawnOccupancyWorld();
    $controller = $world->getGameController();
    (new ReflectionProperty(AbstractGameController::class, 'spawnPoints'))->setValue($controller, [
        AbstractGameController::SPAWN_HUMAN => [new Vector2(100, 144), new Vector2(650, 144)],
        AbstractGameController::SPAWN_RED   => [],
        AbstractGameController::SPAWN_BLUE  => [],
    ]);
    $enemy = new PlayerTee;
    $world->addTee($enemy);
    $character           = addSpawnBlocker($world, new Vector2(550, 144));
    $character->tee      = $enemy;
    $enemy->character    = $character;
    $enemy->viewPosition = new Vector2(100, 144);

    expect($controller->selectSpawnPosition($world, GameConstants::TEAM_RED)?->x)->toBe(100.0);
});

test('team spawning falls back to neutral then enemy points only when preferred points are blocked', function (int $blockedGroups, ?float $expectedX) {
    $world      = createSpawnOccupancyWorld();
    $controller = $world->getGameController();
    $controller->setGameMode('TDM');
    (new ReflectionProperty(AbstractGameController::class, 'spawnPoints'))->setValue($controller, [
        AbstractGameController::SPAWN_RED   => [new Vector2(144, 144)],
        AbstractGameController::SPAWN_HUMAN => [new Vector2(400, 144)],
        AbstractGameController::SPAWN_BLUE  => [new Vector2(656, 144)],
    ]);
    foreach (array_slice([144, 400, 656], 0, $blockedGroups) as $x) {
        foreach ([[0, 0], [-32, 0], [0, -32], [32, 0], [0, 32]] as [$dx, $dy]) {
            addSpawnBlocker($world, new Vector2($x + $dx, 144 + $dy));
        }
    }

    $spawn = $controller->selectSpawnPosition($world, GameConstants::TEAM_RED);

    expect($spawn?->x)->toBe($expectedX);
})->with([
    'own team first'      => [0, 144.0],
    'neutral fallback'    => [1, 400.0],
    'enemy fallback'      => [2, 656.0],
    'all groups occupied' => [3, null],
]);

test('blocked players remain pending and respawn once a position becomes free', function () {
    $world    = createSpawnOccupancyWorld();
    $blockers = [];
    foreach ([[144, 144], [112, 144], [144, 112], [176, 144], [144, 176]] as [$x, $y]) {
        $blockers[] = addSpawnBlocker($world, new Vector2($x, $y));
    }
    $tee = new PlayerTee;
    $world->addTee($tee);

    $world->doTick();
    expect($tee->character)->toBeNull();
    expect($tee->spawning)->toBeTrue();
    $blockers[0]->markForDestruction();
    $world->doTick();

    $character = $tee->character;
    expect($character)->toBeInstanceOf(PvpCharacterEntity::class);
    assert($character instanceof PvpCharacterEntity);
    expect([$character->getPosition()->x, $character->getPosition()->y])->toBe([144.0, 144.0]);
    expect($tee->spawning)->toBeFalse();
});

test('players spawning during the same tick cannot select the same occupied position', function () {
    $world  = createSpawnOccupancyWorld();
    $first  = new PlayerTee;
    $second = new PlayerTee;
    $world->addTee($first);
    $world->addTee($second);

    $world->doTick();

    $firstCharacter  = $first->character;
    $secondCharacter = $second->character;
    assert($firstCharacter !== null && $secondCharacter !== null);
    expect([$firstCharacter->getPosition()->x, $firstCharacter->getPosition()->y])->toBe([144.0, 144.0]);
    expect([$secondCharacter->getPosition()->x, $secondCharacter->getPosition()->y])->toBe([112.0, 144.0]);
});
