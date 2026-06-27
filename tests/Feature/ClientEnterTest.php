<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('addTee marks PlayerTee as spawning', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $tee->name = 'NewPlayer';

    $world->addTee($tee);

    expect($tee->spawning)->toBeTrue();
    expect($tee->teeIndex)->toBe(0);
});

test('addTee broadcasts enter chat message to all tees', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $existing = new PlayerTee;
    $existing->name = 'Existing';
    $existing->teeIndex = 0;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $existing]);

    $newTee = new PlayerTee;
    $newTee->name = 'NewPlayer';

    $world->addTee($newTee);

    // Should send to both the existing tee and the new tee
    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});

test('addTee enter message uses server clientId -1', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $tee->name = 'Joiner';

    // Capture the chunk sent via sendToTee by overriding the mock server's method
    $sentChunk = null;
    $mockServer = $GLOBALS['mockGameServer'];
    $ref = new ReflectionClass($mockServer);
    $method = $ref->getMethod('sendToTee');
    $method->setAccessible(false);

    $world->addTee($tee);

    // The mock server records sentTeeIndexes; verify a single tee got the message
    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0]);
});

test('addTee enter message contains tee name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    // Use a custom mock server to capture the chunk content
    /** @var \TeeFrame\Network\Chunks\AbstractChunk[] $capturedChunks */
    $capturedChunks = [];
    $customServer = new class($capturedChunks) extends \TeeFrame\Server\AbstractServerInstance {
        /** @var int[] */
        public array $sentTeeIndexes = [];

        /** @param \TeeFrame\Network\Chunks\AbstractChunk[] $captured */
        public function __construct(public array &$captured)
        {
            // bypass parent constructor
        }

        protected function boot(): void {}

        protected function selectWorldForNewConnection(): \TeeFrame\Game\AbstractWorld
        {
            throw new \RuntimeException('not implemented');
        }

        public function sendToTee(\TeeFrame\Game\AbstractWorld $world, int $teeIndex, \TeeFrame\Network\Chunks\AbstractChunk $chunk): void
        {
            $this->sentTeeIndexes[] = $teeIndex;
            $this->captured[] = $chunk;
        }
    };

    // Build a world wired to the custom server
    $world = new class('test', new TickHandler, $map, $customServer) extends \TeeFrame\Game\AbstractWorld {
        public function getMotd(\TeeFrame\Game\Tees\AbstractTee $requestingTee): string
        {
            return '';
        }

        protected function bootGameController(): void
        {
            $this->gameController = new \TestGameController($this->tickHandler);
        }

        public function doTick(): void {}
    };

    $tee = new PlayerTee;
    $tee->name = 'FancyName';

    $world->addTee($tee);

    expect($customServer->sentTeeIndexes)->toBe([0]);
    expect($capturedChunks)->toHaveCount(1);
    expect($capturedChunks[0])->toBeInstanceOf(SvChatChunk::class);

    /** @var SvChatChunk $chatChunk */
    $chatChunk = $capturedChunks[0];
    expect($chatChunk->clientId)->toBe(-1);
    expect($chatChunk->text)->toBe("'FancyName' entered and joined the game");
});
