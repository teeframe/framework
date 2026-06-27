<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Map\Map;

/**
 * Concrete game controller for tests — no scoring, no win check.
 */
class TestGameController extends AbstractGameController
{
    public function doTick(): void {}
    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        return 0;
    }
}

/**
 * A mock server with sendToTee tracking.
 */
$GLOBALS['mockGameServer'] = new class extends \TeeFrame\Server\AbstractServerInstance {
    /** @var int[] */
    public array $sentTeeIndexes = [];

    /** @var array<int, \TeeFrame\Network\Chunks\AbstractChunk> */
    public array $sentChunks = [];

    public function __construct()
    {
        // bypass parent constructor
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        throw new \RuntimeException('not implemented');
    }

    public function sendToTee(AbstractWorld $world, int $teeIndex, \TeeFrame\Network\Chunks\AbstractChunk $chunk): void
    {
        $this->sentTeeIndexes[] = $teeIndex;
        $this->sentChunks[]      = $chunk;
    }

    public function resetSentTees(): void
    {
        $this->sentTeeIndexes = [];
        $this->sentChunks     = [];
    }
};

function resetMockServer(): void
{
    $GLOBALS['mockGameServer']->resetSentTees();
}

function createWorld(Map $map): AbstractWorld
{
    return new class('test', new TickHandler, $map, $GLOBALS['mockGameServer']) extends AbstractWorld
    {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        protected function bootGameController(): void
        {
            $this->gameController = new TestGameController($this->tickHandler);
        }

        public function doTick(): void {}
    };
}

/**
 * Build a PlayerInput with sensible defaults; override only what the test cares about.
 *
 * @param array<string, mixed> $overrides
 */
function input(array $overrides = []): PlayerInput
{
    return new PlayerInput(
        direction: (int) ($overrides['direction'] ?? 0),
        targetX: (int) ($overrides['targetX'] ?? 0),
        targetY: (int) ($overrides['targetY'] ?? -1),
        jump: (bool) ($overrides['jump'] ?? false),
        fire: (int) ($overrides['fire'] ?? 0),
        hook: (bool) ($overrides['hook'] ?? false),
        playerFlags: (int) ($overrides['playerFlags'] ?? 0),
        wantedWeapon: (int) ($overrides['wantedWeapon'] ?? 0),
        nextWeapon: (int) ($overrides['nextWeapon'] ?? 0),
        prevWeapon: (int) ($overrides['prevWeapon'] ?? 0),
    );
}

/**
 * Feed a PlayerInput to the character and advance one tick.
 * Mirrors what AbstractWorld::doTick() does: onPredictedInput + applyInput + doTick.
 */
function feedInput(AbstractCharacterEntity $character, PlayerInput $input): void
{
    $character->onPredictedInput($input);
    $character->applyInput();
    $character->doTick();
}
