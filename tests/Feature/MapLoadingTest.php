<?php

use TeeFrame\Map\Collision;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkBase;

class SingleReadMapStream
{
    /** @var resource|null */
    public $context;

    public static string $content = '';
    public static int $opens      = 0;

    protected string $buffer = '';
    protected int $offset    = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->buffer = self::$opens++ === 0 ? self::$content : 'file changed after parsing';

        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = substr($this->buffer, $this->offset, $count);
        $this->offset += strlen($bytes);

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->buffer);
    }

    /** @return array{size: int} */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->buffer)];
    }

    /** @return array{size: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['size' => 1];
    }
}

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

test('loads dm1 map and computes correct crc', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    // The CRC should match what the Teeworlds 0.6 client expects.
    // Verified with: crc32(file_get_contents('dm1.map')) on the reference file.
    // 0xf2159e6e as signed 32-bit = -233464210
    $expectedCrc = -233464210;
    expect($map->getCrc())->toBe($expectedCrc);

    expect($map->getName())->toBe('dm1');

    expect($map->getSize())->toBe(filesize($mapPath));
});

test('map parsing crc size and download data use a single file read', function () use ($mapPath) {
    $content = file_get_contents($mapPath);
    if ($content === false) {
        throw new RuntimeException('Required map fixture is missing');
    }
    if (! stream_wrapper_register('teeframe-map-read', SingleReadMapStream::class)) {
        throw new RuntimeException('Cannot register map read test stream');
    }

    SingleReadMapStream::$content = $content;
    SingleReadMapStream::$opens   = 0;
    try {
        $map = new Map('teeframe-map-read://dm1.map');
        $crc = crc32($content);
        $crc = ($crc & 0x80000000) ? $crc - 0x100000000 : $crc;

        expect(SingleReadMapStream::$opens)->toBe(1);
        expect($map->getSize())->toBe(strlen($content));
        expect($map->getCrc())->toBe($crc);
        expect($map->getRawData())->toBe(array_values(unpack('C*', $content) ?: []));
        expect($map->getGameLayer()?->width)->toBe(60);
        expect($map->getGameLayer()?->height)->toBe(50);
    } finally {
        stream_wrapper_unregister('teeframe-map-read');
        SingleReadMapStream::$content = '';
        SingleReadMapStream::$opens   = 0;
    }
});

test('dm1 map has a game layer with correct dimensions', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    $gameLayer = $map->getGameLayer();
    expect($gameLayer)->not->toBeNull();

    if ($gameLayer === null) {
        throw new RuntimeException('Required map fixture has no game layer');
    }

    expect($gameLayer->width)->toBe(60);
    expect($gameLayer->height)->toBe(50);
});

test('dm1 map collision is initialized', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    $collision = $map->getCollision();
    expect($collision)->not->toBeNull();

    if ($collision === null) {
        throw new RuntimeException('Required map fixture has no collision layer');
    }

    expect($collision->getWidth())->toBe(60);
    expect($collision->getHeight())->toBe(50);
});

test('dm1 map raw data is available for download', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    $rawData = $map->getRawData();
    expect($rawData)->not->toBeEmpty();

    expect(count($rawData))->toBe($map->getSize());
});

test('dm1 map has groups and layers', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    $groups = $map->getGroups();
    expect($groups)->not->toBeEmpty();

    $layers = $map->getLayers();
    expect($layers)->not->toBeEmpty();
});

test('dm1 map crc round-trips correctly through packInt', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);
    $crc = $map->getCrc();

    expect($crc)->toBeInt();
    expect($crc)->toBeLessThan(0); // dm1 CRC has bit 31 set, so it's negative as signed 32-bit

    $packed     = NetworkBase::packInt($crc);
    [$unpacked] = NetworkBase::unpackInt($packed);

    expect($unpacked)->toBe($crc);
});

test('dm1 map has spawn tiles', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map = new Map($mapPath);

    $gameLayer = $map->getGameLayer();
    expect($gameLayer)->not->toBeNull();

    if ($gameLayer === null) {
        throw new RuntimeException('Required map fixture has no game layer');
    }

    $entities = $gameLayer->getEntityPositions();

    expect($entities)->not->toBeEmpty();

    foreach ($entities as $entity) {
        expect($entity['x'])->toBeGreaterThan(0);
        expect($entity['y'])->toBeGreaterThan(0);
        expect($entity['x'])->toBeLessThan($gameLayer->width * 32);
        expect($entity['y'])->toBeLessThan($gameLayer->height * 32);
    }

    $spawnPoints = array_filter($entities, fn (array $e) => $e['type'] === GameLayer::ENTITY_SPAWN);
    expect($spawnPoints)->not->toBeEmpty();
});
