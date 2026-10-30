<?php

require __DIR__.'/../vendor/autoload.php';

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\RawPayload;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

class BenchmarkConnection extends AbstractConnection
{
    protected function handlePacketSending(AbstractPacket $packet): bool
    {
        $packet->encodeToSend();

        return true;
    }

    protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void {}

    protected function handleConnectionOutOfSequence(int $sequence, int $ack): void {}
}

class BenchmarkChunk extends AbstractChunk
{
    public static int $encodes = 0;

    public function __construct()
    {
        parent::__construct(0, NetworkMessages::CL_VOTE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static;
    }

    public function getPayload(): RawPayload
    {
        self::$encodes++;

        return new RawPayload([0]);
    }
}

function benchmark(string $name, Closure $operation, int $iterations = 200): void
{
    for ($i = 0; $i < 20; $i++) {
        $operation();
    }

    $samples = [];
    for ($trial = 0; $trial < 5; $trial++) {
        gc_collect_cycles();
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }
        $samples[] = (hrtime(true) - $start) / $iterations / 1000;
    }
    sort($samples);
    printf("%-30s %10.2f us/op (median of 5)\n", $name, $samples[2]);
}

$connection = new BenchmarkConnection(new TickHandler);
$oldItems   = [];
$newItems   = [];
for ($i = 0; $i < 128; $i++) {
    $oldItems[] = (new ObjProjectileItem(1600 + $i, 1000, 43, 4, 1, 100))->setId($i);
    $newItems[] = (new ObjProjectileItem(1601 + $i, 1000, 43, 4, 1, 100))->setId($i);
}

printf("PHP %s; CLI opcache=%s; JIT=%s\n", PHP_VERSION, ini_get('opcache.enable_cli'), ini_get('opcache.jit'));
benchmark('full snapshot: 128 items', function () use ($connection, $oldItems): void {
    $connection->reset();
    $connection->snaps()->sendItems(100, $oldItems);
});
benchmark('full + delta: 128 items', function () use ($connection, $oldItems, $newItems): void {
    $connection->reset();
    $connection->snaps()->sendItems(100, $oldItems);
    $connection->snaps()->setLastAckedTick(100);
    $connection->snaps()->sendItems(101, $newItems);
});
benchmark('queue + send: 64 chunks', function () use ($connection): void {
    $connection->reset();
    for ($i = 0; $i < 64; $i++) {
        $connection->chunks()->add(new BenchmarkChunk);
    }
    $connection->chunks()->send();
});
benchmark('payload: 256 integers', function (): void {
    $payload = new RawPayload;
    for ($i = 0; $i < 256; $i++) {
        $payload->addInt($i);
    }
});

$connection->reset();
BenchmarkChunk::$encodes = 0;
for ($i = 0; $i < 64; $i++) {
    $connection->chunks()->add(new BenchmarkChunk);
}
$connection->chunks()->send();
printf("Chunk encodes for one 64-chunk queue: %d\n", BenchmarkChunk::$encodes);
