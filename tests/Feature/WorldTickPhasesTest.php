<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;

class WorldTickPhaseTestWorld extends TestWorld
{
    /** @var list<string> */
    public array $phases                = [];
    public bool $resetOnControllerTick  = false;
    public ?bool $pauseOnControllerTick = null;

    protected function bootGameController(): void
    {
        $this->gameController = new class($this->tickHandler, $this) extends PvpGameController
        {
            public function __construct(TickHandler $clock, protected WorldTickPhaseTestWorld $testWorld)
            {
                parent::__construct($clock);
            }

            public function doTick(): void
            {
                $this->testWorld->onControllerTick();
                parent::doTick();
            }
        };
    }

    public function onControllerTick(): void
    {
        $this->phases[] = 'controller';
        if ($this->resetOnControllerTick) {
            $this->resetGame();
        }
        if ($this->pauseOnControllerTick !== null) {
            $this->setPaused($this->pauseOnControllerTick);
        }
    }

    protected function applyPlayerInputs(int $currentTick): void
    {
        $this->phases[] = 'inputs';
        parent::applyPlayerInputs($currentTick);
    }

    protected function resetRound(int $currentTick): void
    {
        $this->phases[] = 'reset';
        parent::resetRound($currentTick);
    }

    protected function tickPausedEntities(): void
    {
        $this->phases[] = 'paused-entities';
        parent::tickPausedEntities();
    }

    protected function tickEntities(): void
    {
        $this->phases[] = 'entities';
        parent::tickEntities();
    }

    protected function tickPlayers(int $currentTick): void
    {
        $this->phases[] = 'players';
        parent::tickPlayers($currentTick);
    }

    protected function updateSpectatorViews(): void
    {
        $this->phases[] = 'views';
        parent::updateSpectatorViews();
    }

    protected function performReload(Map $map): void
    {
        $this->phases[] = 'reload';
        parent::performReload($map);
    }
}

test('world ticks process inputs controller entities players and views in order', function () {
    $world = new WorldTickPhaseTestWorld(new TickHandler(100), new Map);

    $world->doTick();

    expect($world->phases)->toBe(['inputs', 'controller', 'entities', 'players', 'views']);
});

test('paused ticks do not run player respawns or spectator following', function () {
    $world = new WorldTickPhaseTestWorld(new TickHandler(100), new Map);
    $world->setPaused(true);

    $world->doTick();

    expect($world->phases)->toBe(['inputs', 'controller', 'paused-entities']);
});

test('controller pause changes take effect before ticking entities and players', function (bool $paused) {
    $world = new WorldTickPhaseTestWorld(new TickHandler(100), new Map);
    $world->setPaused(! $paused);
    $world->pauseOnControllerTick = $paused;

    $world->doTick();

    expect($world->isPaused())->toBe($paused);
    expect($world->phases)->toBe($paused
        ? ['inputs', 'controller', 'paused-entities']
        : ['inputs', 'controller', 'entities', 'players', 'views']);
})->with([true, false]);

test('pause changes during entity ticks do not switch the chosen tick phases midway', function (bool $paused) {
    $world = new WorldTickPhaseTestWorld(new TickHandler(100), new Map);
    $world->setPaused($paused);
    $world->addEntity(new class($world, new Vector2(0, 0)) extends AbstractEntity
    {
        public function doTick(): void
        {
            $this->world->setPaused(true);
        }

        public function tickPaused(): void
        {
            $this->world->setPaused(false);
        }

        public function getHitBoxRadius(): int
        {
            return 0;
        }

        public function doSnap(AbstractTee $requestingTee): array
        {
            return [];
        }
    });

    $world->doTick();

    expect($world->isPaused())->toBe(! $paused);
    expect($world->phases)->toBe($paused
        ? ['inputs', 'controller', 'paused-entities']
        : ['inputs', 'controller', 'entities', 'players', 'views']);
})->with([true, false]);

test('controller requested round resets happen before entity ticks', function () {
    $world                        = new WorldTickPhaseTestWorld(new TickHandler(100), new Map);
    $world->resetOnControllerTick = true;

    $world->doTick();

    expect($world->phases)->toBe(['inputs', 'controller', 'reset', 'entities', 'players', 'views']);
    expect($world->isResetRequested())->toBeFalse();
});

test('a pending reload stops the tick before controller and entity processing', function () {
    $world       = new WorldTickPhaseTestWorld(new TickHandler(100), new Map(__DIR__.'/../dm1.map'));
    $previousMap = $world->getMap();
    $world->reload();

    $world->doTick();

    expect($world->phases)->toBe(['inputs', 'reload']);
    expect($world->getMap())->not->toBe($previousMap);
});
