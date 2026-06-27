<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('removeTee broadcasts leave message without reason', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $leaving = new PlayerTee;
    $leaving->name = 'Leaver';
    $leaving->teeIndex = 0;

    $staying = new PlayerTee;
    $staying->name = 'Stayer';
    $staying->teeIndex = 1;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $leaving, 1 => $staying]);

    $world->removeTee($leaving);

    // Message sent to both tees (including the leaving one) before removal
    expect($GLOBALS['mockGameServer']->sentTeeIndexes)->toBe([0, 1]);
});

test('removeTee broadcasts leave message with reason', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $server = new class extends \TeeFrame\Server\AbstractServerInstance {
        /** @var int[] */
        public array $sentTeeIndexes = [];

        /** @var \TeeFrame\Network\Chunks\AbstractChunk[] */
        public array $captured = [];

        public function __construct()
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

    $world = new class('test', new TickHandler, $map, $server) extends \TeeFrame\Game\AbstractWorld {
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

    $leaving = new PlayerTee;
    $leaving->name = 'Leaver';
    $leaving->teeIndex = 0;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $leaving]);

    $world->removeTee($leaving, 'timeout');

    expect($server->sentTeeIndexes)->toBe([0]);
    expect($server->captured)->toHaveCount(1);
    expect($server->captured[0])->toBeInstanceOf(SvChatChunk::class);

    /** @var SvChatChunk $chatChunk */
    $chatChunk = $server->captured[0];
    expect($chatChunk->clientId)->toBe(-1);
    expect($chatChunk->text)->toBe("'Leaver' has left the game (timeout)");
});

test('removeTee leave message without reason has plain format', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $server = new class extends \TeeFrame\Server\AbstractServerInstance {
        /** @var int[] */
        public array $sentTeeIndexes = [];

        /** @var \TeeFrame\Network\Chunks\AbstractChunk[] */
        public array $captured = [];

        public function __construct()
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

    $world = new class('test', new TickHandler, $map, $server) extends \TeeFrame\Game\AbstractWorld {
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

    $leaving = new PlayerTee;
    $leaving->name = 'QuietLeaver';
    $leaving->teeIndex = 0;

    $ref = new ReflectionClass($world);
    $prop = $ref->getProperty('tees');
    $prop->setAccessible(true);
    $prop->setValue($world, [0 => $leaving]);

    $world->removeTee($leaving);

    expect($server->captured)->toHaveCount(1);
    expect($server->captured[0])->toBeInstanceOf(SvChatChunk::class);

    /** @var SvChatChunk $chatChunk */
    $chatChunk = $server->captured[0];
    expect($chatChunk->clientId)->toBe(-1);
    expect($chatChunk->text)->toBe("'QuietLeaver' has left the game");
});

test('removeTee frees the tee index', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee1 = new PlayerTee;
    $tee1->name = 'First';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Second';
    $world->addTee($tee2);

    expect($tee1->teeIndex)->toBe(0);
    expect($tee2->teeIndex)->toBe(1);

    $world->removeTee($tee1);

    $tee3 = new PlayerTee;
    $tee3->name = 'Third';
    $world->addTee($tee3);

    // Should reuse the freed index 0
    expect($tee3->teeIndex)->toBe(0);
});
