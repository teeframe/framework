<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjPickupItem;

class LifecycleTestEntity extends AbstractEntity
{
    public int $resets      = 0;
    public int $ticks       = 0;
    public int $pausedTicks = 0;

    public function __construct(
        AbstractWorld $world,
        protected bool $persistent = false,
        protected ?int $fixedSnapId = null,
        protected int $snapCount = 1,
    ) {
        parent::__construct($world, new Vector2(0, 0));
    }

    public function reset(): void
    {
        $this->resets++;
        if (! $this->persistent) {
            parent::reset();
        }
    }

    public function getFixedSnapId(): ?int
    {
        return $this->fixedSnapId;
    }

    public function getHitBoxRadius(): int
    {
        return 0;
    }

    public function doTick(): void
    {
        $this->ticks++;
    }

    public function tickPaused(): void
    {
        $this->pausedTicks++;
    }

    /** @return ObjPickupItem[] */
    public function doSnap(AbstractTee $requestingTee): array
    {
        $snaps = [];
        for ($i = 0; $i < $this->snapCount; $i++) {
            $snaps[] = new ObjPickupItem(x: $i, y: 0, type: 0, subType: 0);
        }

        return $snaps;
    }
}

/** @return list<int> */
function lifecycleSnapshotIds(AbstractWorld $world, AbstractTee $tee): array
{
    return array_values(array_map(
        fn (ObjPickupItem $item) => $item->getId(),
        array_filter($world->doSnap($tee), fn ($item) => $item instanceof ObjPickupItem),
    ));
}

test('custom entities can survive round resets through their reset hook', function () {
    $world      = new TestWorld(new TickHandler(100), new Map);
    $tee        = new PlayerTee;
    $transient  = new LifecycleTestEntity($world, snapCount: 2);
    $persistent = new LifecycleTestEntity($world, persistent: true, snapCount: 2);
    $world->addTee($tee);
    $tee->team = GameConstants::TEAM_SPECTATORS;
    $world->addEntity($transient);
    $world->addEntity($persistent);
    expect(lifecycleSnapshotIds($world, $tee))->toBe([0, 1, 2, 3]);

    $world->resetGame();
    $world->doTick();

    expect($world->getEntities())->toBe([$persistent]);
    expect($transient->resets)->toBe(1);
    expect($transient->isToDestroy())->toBeTrue();
    expect($transient->ticks)->toBe(0);
    expect($persistent->resets)->toBe(1);
    expect($persistent->ticks)->toBe(1);
    expect($persistent->isToDestroy())->toBeFalse();
    expect(lifecycleSnapshotIds($world, $tee))->toBe([2, 3]);

    $replacement = new LifecycleTestEntity($world, snapCount: 2);
    $world->addEntity($replacement);
    $ids = lifecycleSnapshotIds($world, $tee);
    expect(array_slice($ids, 0, 2))->toBe([2, 3]);
    $reused = array_slice($ids, 2);
    sort($reused);
    expect($reused)->toBe([0, 1]);
});

test('custom fixed snapshot ids including zero bypass the dynamic id pool', function (int $id) {
    $world = new TestWorld(new TickHandler(100), new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $entity = new LifecycleTestEntity($world, fixedSnapId: $id);
    $world->addEntity($entity);

    expect(lifecycleSnapshotIds($world, $tee))->toBe([$id]);
    expect(lifecycleSnapshotIds($world, $tee))->toBe([$id]);
    expect($entity->getAllocatedSnapIds())->toBe([]);
})->with([0, 42]);

test('dynamic entity snapshot ids stay stable across recipients and snapshots', function () {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $first  = new PlayerTee;
    $second = new PlayerTee;
    $world->addTee($first);
    $world->addTee($second);
    $world->addEntity(new LifecycleTestEntity($world, snapCount: 3));

    expect(lifecycleSnapshotIds($world, $first))->toBe([0, 1, 2]);
    expect(lifecycleSnapshotIds($world, $second))->toBe([0, 1, 2]);
    expect(lifecycleSnapshotIds($world, $first))->toBe([0, 1, 2]);
});

test('character and flag hooks retain their protocol-defined snapshot ids', function () {
    $world     = new TestWorld(new TickHandler(100), new Map);
    $tee       = new PlayerTee;
    $character = new PvpCharacterEntity($world, new Vector2(0, 0));
    expect($character->getFixedSnapId())->toBeNull();
    $world->addTee($tee);
    $character->spawn(new Vector2(0, 0), $tee);

    expect($character->getFixedSnapId())->toBe(0);
    $red  = new FlagEntity($world, new Vector2(0, 0), GameConstants::TEAM_RED);
    $blue = new FlagEntity($world, new Vector2(0, 0), GameConstants::TEAM_BLUE);
    expect($red->getFixedSnapId())->toBe(GameConstants::TEAM_RED);
    expect($blue->getFixedSnapId())->toBe(GameConstants::TEAM_BLUE);
});

test('paused entity ticks preserve entities and do not run their active tick', function () {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $entity = new LifecycleTestEntity($world);
    $world->addEntity($entity);
    $world->setPaused(true);

    $world->doTick();

    expect($entity->ticks)->toBe(0);
    expect($entity->pausedTicks)->toBe(1);
    expect($world->getEntities())->toBe([$entity]);
    $world->setPaused(false);
    $world->doTick();
    expect($entity->ticks)->toBe(1);
    expect($entity->pausedTicks)->toBe(1);
});
