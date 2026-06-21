<?php

use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Map\Collision;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('loads dm1 map and computes correct crc', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    // The CRC should match what the Teeworlds 0.6 client expects.
    // Verified with: crc32(file_get_contents('dm1.map')) on the reference file.
    // 0xf2159e6e as signed 32-bit = -233464210
    $expectedCrc = -233464210;
    expect($map->getCrc())->toBe($expectedCrc);

    // Map name should be extracted from filename
    expect($map->getName())->toBe('dm1');

    // Map size should match the file size
    expect($map->getSize())->toBe(filesize($mapPath));
});

test('dm1 map has a game layer with correct dimensions', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $gameLayer = $map->getGameLayer();
    expect($gameLayer)->not->toBeNull();

    if ($gameLayer === null) {
        return;
    }

    // dm1.map game layer dimensions
    expect($gameLayer->width)->toBe(60);
    expect($gameLayer->height)->toBe(50);
});

test('dm1 map collision is initialized', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $collision = $map->getCollision();
    expect($collision)->not->toBeNull();

    if ($collision === null) {
        return;
    }

    // Collision dimensions should match game layer
    expect($collision->getWidth())->toBe(60);
    expect($collision->getHeight())->toBe(50);
});

test('dm1 map raw data is available for download', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $rawData = $map->getRawData();
    expect($rawData)->not->toBeEmpty();

    // Raw data length should match file size
    expect(count($rawData))->toBe($map->getSize());
});

test('dm1 map has groups and layers', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $groups = $map->getGroups();
    expect($groups)->not->toBeEmpty();

    $layers = $map->getLayers();
    expect($layers)->not->toBeEmpty();
});

test('dm1 map crc round-trips correctly through packInt', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);
    $crc = $map->getCrc();

    // The CRC should be a valid signed 32-bit integer
    expect($crc)->toBeInt();
    expect($crc)->toBeLessThan(0); // dm1 CRC has bit 31 set, so it's negative as signed 32-bit

    // Pack and unpack through Teeworlds' variable-length int encoding
    $packed = \TeeFrame\Network\NetworkBase::packInt($crc);
    [$unpacked] = \TeeFrame\Network\NetworkBase::unpackInt($packed);

    expect($unpacked)->toBe($crc);
});

test('dm1 map has spawn tiles', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    $map = new Map($mapPath);

    $gameLayer = $map->getGameLayer();
    expect($gameLayer)->not->toBeNull();

    if ($gameLayer === null) {
        return;
    }

    $entities = $gameLayer->getEntityPositions();

    // dm1.map should have spawn points
    expect($entities)->not->toBeEmpty();

    // Check that spawn entities have valid positions within the map bounds
    foreach ($entities as $entity) {
        expect($entity['x'])->toBeGreaterThan(0);
        expect($entity['y'])->toBeGreaterThan(0);
        expect($entity['x'])->toBeLessThan($gameLayer->width * 32);
        expect($entity['y'])->toBeLessThan($gameLayer->height * 32);
    }

    // Verify at least one ENTITY_SPAWN (192) exists
    $spawnPoints = array_filter($entities, fn (array $e) => $e['type'] === GameLayer::ENTITY_SPAWN);
    expect($spawnPoints)->not->toBeEmpty();
});
