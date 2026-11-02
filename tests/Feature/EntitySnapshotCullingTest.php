<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjLaserItem;
use TeeFrame\Network\SnapItems\ObjPickupItem;

/** @return array{TestWorld, PlayerTee, PlayerTee} */
function entitySnapshotCullingFixture(): array
{
    $map = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(128, 128, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 128 * 128));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $world                               = new TestWorld(new TickHandler(100), $map);
    $world->getTuneController()->gravity = 0;
    $viewer                              = new PlayerTee;
    $owner                               = new PlayerTee;
    $world->addTee($viewer);
    $world->addTee($owner);
    $viewer->viewPosition = new Vector2(2048, 2048);

    return [$world, $viewer, $owner];
}

test('laser snapshot culling measures the closest point on the current beam segment', function (int $fromX, int $fromY, int $toX, int $toY, bool $visible) {
    [$world, $viewer, $owner] = entitySnapshotCullingFixture();
    $from                     = $viewer->viewPosition->add(new Vector2($fromX, $fromY));
    $to                       = $viewer->viewPosition->add(new Vector2($toX, $toY));
    $direction                = $to->diff($from);
    $reach                    = $direction->length();
    $owner->viewPosition      = clone $from;
    $laser                    = new PvpLaserEntity($world, $from, $reach > 0 ? $direction->normalize() : new Vector2(1, 0), $reach, $owner->teeIndex);
    $world->addEntity($laser);

    $snapshots = array_values(array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjLaserItem));

    expect($snapshots)->toHaveCount($visible ? 1 : 0);
    if ($visible) {
        expect($snapshots[0]->getInts())->toBe([(int) $to->x, (int) $to->y, (int) $from->x, (int) $from->y, 100]);
    }
    expect(array_filter($world->doSnap($owner), fn ($item) => $item instanceof ObjLaserItem))->toHaveCount(1);
})->with([
    'default reach with only the start in range'               => [400, 0, 1200, 0, true],
    'default reach with only the end in range'                 => [1200, 0, 400, 0, true],
    'both ends outside but middle crosses the viewer'          => [-1500, 0, 1500, 0, true],
    'default reach with both ends outside but middle in range' => [-400, 1050, 400, 1050, true],
    'segment exactly on the range boundary'                    => [-400, 1100, 400, 1100, true],
    'segment just outside the range boundary'                  => [-400, 1101, 400, 1101, false],
    'segment entirely outside the range'                       => [1200, 0, 2000, 0, false],
    'vertical segment crossing the viewer'                     => [0, -1500, 0, 1500, true],
    'diagonal segment crossing the viewer'                     => [-800, 800, 800, -800, true],
    'zero length segment in range'                             => [100, 0, 100, 0, true],
    'zero length segment outside the range'                    => [1200, 0, 1200, 0, false],
]);

test('a normal hook launch remains visible when its tee is outside the culling range', function () {
    [$world, $viewer, $owner] = entitySnapshotCullingFixture();
    $position                 = $viewer->viewPosition->add(new Vector2(1200, 0));
    $character                = new PvpCharacterEntity($world, $position);
    $character->spawn($position, $owner);
    $world->addEntity($character);
    $character->onPredictedInput(input(['targetX' => -100, 'targetY' => 0, 'hook' => true]));

    $world->doTick();
    $snapshot = array_find($world->doSnap($viewer), fn ($item) => $item instanceof ObjCharacterItem);

    expect($character->hookState)->toBe(GameConstants::HOOK_FLYING);
    expect($viewer->viewPosition->distance($character->getPosition()))->toBe(1200.0);
    expect($viewer->viewPosition->distance($character->hookPos))->toBe(1078.0);
    expect($snapshot)->toBeInstanceOf(ObjCharacterItem::class);
    assert($snapshot instanceof ObjCharacterItem);
    expect([$snapshot->x, $snapshot->y, $snapshot->hookX, $snapshot->hookY])->toBe([3248, 2048, 3126, 2048]);
});

test('active hook snapshot culling includes the chain without exposing the owners combat stats', function (int $hookState, int $bodyX, int $bodyY, int $hookX, int $hookY, bool $visible) {
    [$world, $viewer, $owner] = entitySnapshotCullingFixture();
    $position                 = $viewer->viewPosition->add(new Vector2($bodyX, $bodyY));
    $character                = new PvpCharacterEntity($world, $position);
    $character->spawn($position, $owner);
    $character->hookState                                = $hookState;
    $character->hookPos                                  = $viewer->viewPosition->add(new Vector2($hookX, $hookY));
    $character->health                                   = 7;
    $character->armor                                    = 4;
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 9;
    $world->addEntity($character);

    $snapshots = array_values(array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjCharacterItem));

    expect($snapshots)->toHaveCount($visible ? 1 : 0);
    if ($visible) {
        $snapshot = $snapshots[0];
        expect($snapshot->getId())->toBe($owner->teeIndex);
        expect([$snapshot->x, $snapshot->y, $snapshot->hookX, $snapshot->hookY, $snapshot->hookState])
            ->toBe([(int) $position->x, (int) $position->y, (int) $character->hookPos->x, (int) $character->hookPos->y, $hookState]);
        expect([$snapshot->health, $snapshot->armor, $snapshot->ammoCount])->toBe([0, 0, 0]);
    }
})->with([
    'flying'              => GameConstants::HOOK_FLYING,
    'grabbed'             => GameConstants::HOOK_GRABBED,
    'starting retraction' => GameConstants::HOOK_RETRACT_START,
    'retracting'          => GameConstants::HOOK_RETRACT_START + 1,
    'ending retraction'   => GameConstants::HOOK_RETRACT_END,
])->with([
    'default length reaching into range'                        => [1200, 0, 820, 0, true],
    'default length touching the range boundary'                => [1480, 0, 1100, 0, true],
    'default length just outside the range'                     => [1481, 0, 1101, 0, false],
    'hook pointing away from the viewer'                        => [1200, 0, 1580, 0, false],
    'tee in range with hook outside'                            => [900, 0, 1280, 0, true],
    'long chain crossing the viewer with both ends outside'     => [-1500, 0, 1500, 0, true],
    'diagonal chain crossing the viewer with both ends outside' => [-800, 800, 800, -800, true],
    'zero length hook in range'                                 => [100, 0, 100, 0, true],
    'zero length hook outside the range'                        => [1200, 0, 1200, 0, false],
]);

test('idle and fully retracted hooks do not keep distant characters in snapshots', function (int $hookState) {
    [$world, $viewer, $owner] = entitySnapshotCullingFixture();
    $position                 = $viewer->viewPosition->add(new Vector2(1200, 0));
    $character                = new PvpCharacterEntity($world, $position);
    $character->spawn($position, $owner);
    $character->hookState = $hookState;
    $character->hookPos   = clone $viewer->viewPosition;
    $world->addEntity($character);

    expect(array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjCharacterItem))->toBeEmpty();
})->with([GameConstants::HOOK_IDLE, GameConstants::HOOK_RETRACTED]);

test('point entity snapshot culling keeps the same range boundary', function (int $distance, bool $visible) {
    [$world, $viewer] = entitySnapshotCullingFixture();
    $pickup           = new PickupEntity($world, $viewer->viewPosition->add(new Vector2($distance, 0)), GameConstants::POWERUP_HEALTH);
    $world->addEntity($pickup);

    expect(array_filter($world->doSnap($viewer), fn ($item) => $item instanceof ObjPickupItem))->toHaveCount($visible ? 1 : 0);
})->with(['inside' => [1099, true], 'boundary' => [1100, true], 'outside' => [1101, false]]);

test('spectators still receive distant point entities lasers and characters with hooks', function () {
    [$world, $viewer, $owner] = entitySnapshotCullingFixture();
    $from                     = $viewer->viewPosition->add(new Vector2(1200, 0));
    $laser                    = new PvpLaserEntity($world, $from, new Vector2(1, 0), 800, $owner->teeIndex);
    $world->addEntity($laser);
    $position  = $viewer->viewPosition->add(new Vector2(-1500, 0));
    $character = new PvpCharacterEntity($world, $position);
    $character->spawn($position, $owner);
    $character->hookState = GameConstants::HOOK_GRABBED;
    $character->hookPos   = $viewer->viewPosition->add(new Vector2(-1300, 0));
    $world->addEntity($character);
    $pickup = new PickupEntity($world, $from, GameConstants::POWERUP_HEALTH);
    $world->addEntity($pickup);

    $snapshots = $world->doSnap($viewer);
    expect(array_filter($snapshots, fn ($item) => $item instanceof ObjLaserItem || $item instanceof ObjCharacterItem || $item instanceof ObjPickupItem))->toBeEmpty();
    $viewer->setTeam(GameConstants::TEAM_SPECTATORS);
    $snapshots = $world->doSnap($viewer);
    expect(array_filter($snapshots, fn ($item) => $item instanceof ObjLaserItem))->toHaveCount(1);
    expect(array_filter($snapshots, fn ($item) => $item instanceof ObjCharacterItem))->toHaveCount(1);
    expect(array_filter($snapshots, fn ($item) => $item instanceof ObjPickupItem))->toHaveCount(1);

    $laser->markForDestruction();
    $character->die();
    $pickup->markForDestruction();
    $snapshots = $world->doSnap($viewer);
    expect(array_filter($snapshots, fn ($item) => $item instanceof ObjLaserItem || $item instanceof ObjCharacterItem || $item instanceof ObjPickupItem))->toBeEmpty();
});
