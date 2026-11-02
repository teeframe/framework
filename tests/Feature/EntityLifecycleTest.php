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
    public int $resets        = 0;
    public int $ticks         = 0;
    public int $pausedTicks   = 0;
    public int $deferredTicks = 0;

    /** @var (Closure(): void)|null */
    public ?Closure $onTick = null;

    /** @var (Closure(): void)|null */
    public ?Closure $onReset = null;

    /** @var (Closure(): void)|null */
    public ?Closure $onDeferred = null;

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
        $this->onReset?->__invoke();
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
        $this->onTick?->__invoke();
    }

    public function doTickDeferred(): void
    {
        $this->deferredTicks++;
        $this->onDeferred?->__invoke();
    }

    public function doTickPaused(): void
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

    $world->requestRoundReset();
    $world->doTick();

    expect($world->getEntities())->toBe([$persistent]);
    expect($transient->resets)->toBe(1);
    expect($transient->isMarkedForDestruction())->toBeTrue();
    expect($transient->ticks)->toBe(0);
    expect($persistent->resets)->toBe(1);
    expect($persistent->ticks)->toBe(1);
    expect($persistent->isMarkedForDestruction())->toBeFalse();
    expect(lifecycleSnapshotIds($world, $tee))->toBe([2, 3]);

    $replacement = new LifecycleTestEntity($world, snapCount: 2);
    $world->addEntity($replacement);
    $ids = lifecycleSnapshotIds($world, $tee);
    expect(array_slice($ids, 0, 2))->toBe([2, 3]);
    $reused = array_slice($ids, 2);
    sort($reused);
    expect($reused)->toBe([0, 1]);
});

test('destroying many entities during a tick preserves survivor keys and releases every snapshot id', function () {
    $world     = new TestWorld(new TickHandler(100), new Map);
    $viewer    = new PlayerTee;
    $survivors = [];
    for ($i = 0; $i < 64; $i++) {
        $entity = new LifecycleTestEntity($world);
        $world->addEntity($entity);
        if ($i % 2 === 0) {
            $entity->onTick = $entity->markForDestruction(...);
        } else {
            $survivors[$i] = $entity;
        }
    }
    expect(lifecycleSnapshotIds($world, $viewer))->toBe(range(0, 63));

    $world->doTick();

    expect($world->getEntities())->toBe($survivors);
    expect(lifecycleSnapshotIds($world, $viewer))->toBe(range(1, 63, 2));
    foreach ($survivors as $entity) {
        expect($entity->ticks)->toBe(1);
        $world->addEntity(new LifecycleTestEntity($world));
    }
    $ids = array_slice(lifecycleSnapshotIds($world, $viewer), 32);
    sort($ids);
    expect($ids)->toBe(range(0, 62, 2));
});

test('entities spawned during ticking survive cleanup and start ticking on the next pass', function () {
    $world           = new TestWorld(new TickHandler(100), new Map);
    $spawner         = new LifecycleTestEntity($world);
    $survivor        = new LifecycleTestEntity($world);
    $spawned         = new LifecycleTestEntity($world);
    $spawner->onTick = function () use ($world, $spawner, $spawned): void {
        $world->addEntity($spawned);
        $spawner->markForDestruction();
    };
    $world->addEntity($spawner);
    $world->addEntity($survivor);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);

    $world->doTick();

    expect($world->getEntities())->toBe([1 => $survivor, 2 => $spawned]);
    expect($spawned->ticks)->toBe(0);
    expect($spawned->deferredTicks)->toBe(0);
    expect($survivor->ticks)->toBe(1);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([1, 0]);
    $world->doTick();
    expect($spawned->ticks)->toBe(1);
    expect($spawned->deferredTicks)->toBe(1);
    expect($survivor->ticks)->toBe(2);
});

test('entities spawned by reset hooks are retained without resetting them in the same pass', function () {
    $world            = new TestWorld(new TickHandler(100), new Map);
    $spawner          = new LifecycleTestEntity($world);
    $persistent       = new LifecycleTestEntity($world, persistent: true);
    $spawned          = new LifecycleTestEntity($world);
    $spawner->onReset = function () use ($world, $spawned): void {
        $world->addEntity($spawned);
    };
    $world->addEntity($spawner);
    $world->addEntity($persistent);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);

    $world->requestRoundReset();
    $world->doTick();

    expect($world->getEntities())->toBe([$persistent, $spawned]);
    expect($spawner->resets)->toBe(1);
    expect($spawner->ticks)->toBe(0);
    expect($persistent->resets)->toBe(1);
    expect($persistent->ticks)->toBe(1);
    expect($spawned->resets)->toBe(0);
    expect($spawned->ticks)->toBe(1);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([1, 0]);
});

test('explicit removal during entity hooks does not free snapshot ids twice', function (bool $duringReset) {
    $world    = new TestWorld(new TickHandler(100), new Map);
    $entity   = new LifecycleTestEntity($world);
    $survivor = new LifecycleTestEntity($world, persistent: true);
    $remove   = function () use ($world, $entity): void {
        $entity->markForDestruction();
        $world->removeEntity($entity);
    };
    if ($duringReset) {
        $entity->onReset = $remove;
    } else {
        $entity->onTick = $remove;
    }
    $world->addEntity($entity);
    $world->addEntity($survivor);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);
    if ($duringReset) {
        $world->requestRoundReset();
    }

    $world->doTick();
    $world->removeEntity($entity);

    expect(array_values($world->getEntities()))->toBe([$survivor]);
    $world->addEntity(new LifecycleTestEntity($world));
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([1, 0]);
})->with([false, true]);

test('entities removed by another entity are not processed later in the same pass', function (bool $duringReset) {
    $world   = new TestWorld(new TickHandler(100), new Map);
    $remover = new LifecycleTestEntity($world, persistent: true);
    $removed = new LifecycleTestEntity($world);
    $remove  = function () use ($world, $removed): void {
        $world->removeEntity($removed);
    };
    if ($duringReset) {
        $remover->onReset = $remove;
    } else {
        $remover->onTick = $remove;
    }
    $world->addEntity($remover);
    $world->addEntity($removed);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);
    if ($duringReset) {
        $world->requestRoundReset();
    }

    $world->doTick();

    expect($world->getEntities())->toBe([$remover]);
    expect($removed->resets)->toBe(0);
    expect($removed->ticks)->toBe(0);
    $world->addEntity(new LifecycleTestEntity($world));
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);
})->with([false, true]);

test('world entities complete their active ticks before the deferred pass', function () {
    $world         = new TestWorld(new TickHandler(100), new Map);
    $first         = new LifecycleTestEntity($world);
    $second        = new LifecycleTestEntity($world);
    $order         = [];
    $first->onTick = function () use (&$order): void {
        $order[] = 'first tick';
    };
    $second->onTick = function () use (&$order): void {
        $order[] = 'second tick';
    };
    $first->onDeferred = function () use (&$order): void {
        $order[] = 'first deferred';
    };
    $second->onDeferred = function () use (&$order): void {
        $order[] = 'second deferred';
    };
    $world->addEntity($first);
    $world->addEntity($second);

    $world->doTick();

    expect($order)->toBe(['first tick', 'second tick', 'first deferred', 'second deferred']);
    $world->setPaused(true);
    $world->doTick();
    expect($first->deferredTicks)->toBe(1);
    expect($second->deferredTicks)->toBe(1);
});

test('deferred cleanup frees snapshot ids once when hooks destroy or remove entities', function (bool $removeDirectly) {
    $world              = new TestWorld(new TickHandler(100), new Map);
    $entity             = new LifecycleTestEntity($world);
    $survivor           = new LifecycleTestEntity($world);
    $entity->onDeferred = function () use ($world, $entity, $removeDirectly): void {
        $entity->markForDestruction();
        if ($removeDirectly) {
            $world->removeEntity($entity);
        }
    };
    $world->addEntity($entity);
    $world->addEntity($survivor);
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([0, 1]);

    $world->doTick();

    expect(array_values($world->getEntities()))->toBe([$survivor]);
    expect($entity->deferredTicks)->toBe(1);
    $world->addEntity(new LifecycleTestEntity($world));
    expect(lifecycleSnapshotIds($world, new PlayerTee))->toBe([1, 0]);
})->with([false, true]);

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
