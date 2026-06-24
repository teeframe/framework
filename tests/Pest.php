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
use TeeFrame\Map\Map;

/**
 * A mock server with sendToTee tracking.
 */
$GLOBALS['mockGameServer'] = new class extends \TeeFrame\Server\AbstractServerInstance {
    /** @var int[] */
    public array $sentTeeIndexes = [];

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
    }

    public function resetSentTees(): void
    {
        $this->sentTeeIndexes = [];
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

        public function doTick(): void {}
    };
}
