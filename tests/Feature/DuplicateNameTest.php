<?php

use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

test('setClientName sets the name when no collision', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, 'Alice');

    expect($tee->name)->toBe('Alice');
});

test('setClientName trims whitespace from the name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, "  Bob  ");

    expect($tee->name)->toBe('Bob');
});

test('setClientName rejects empty name and keeps it empty', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, '   ');

    // Empty after trim: trySetClientName returns false, auto-rename loop also
    // fails because "(1)" + "" = "(1)" which is non-empty, so it should resolve.
    expect($tee->name)->toBe('(1)');
});

test('setClientName auto-renames on duplicate to (1)name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->getServer()->setClientName($world, $first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;
    $world->getServer()->setClientName($world, $second, 'Alice');

    expect($first->name)->toBe('Alice');
    expect($second->name)->toBe('(1)Alice');
});

test('setClientName auto-renames with incrementing suffix for multiple duplicates', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->getServer()->setClientName($world, $first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;
    $world->getServer()->setClientName($world, $second, 'Alice');
    $world->addTee($second);

    $third = new PlayerTee;
    $world->getServer()->setClientName($world, $third, 'Alice');

    expect($first->name)->toBe('Alice');
    expect($second->name)->toBe('(1)Alice');
    expect($third->name)->toBe('(2)Alice');
});

test('setClientName does not rename when the tee keeps its own name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, 'Alice');
    $world->addTee($tee);

    // Re-setting the same name on the same tee should not trigger auto-rename.
    $world->getServer()->setClientName($world, $tee, 'Alice');

    expect($tee->name)->toBe('Alice');
});

test('setClientName cleans control characters from the name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    // \x01 is a control character (< 32), should be replaced with space, then trimmed.
    $world->getServer()->setClientName($world, $tee, "\x01Alice\x01");

    expect($tee->name)->toBe('Alice');
});

test('trySetClientName returns false for empty name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;

    expect($world->getServer()->trySetClientName($world, $tee, ''))->toBeFalse();
    expect($world->getServer()->trySetClientName($world, $tee, "   "))->toBeFalse();
});

test('trySetClientName returns false when name is taken by another tee', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->getServer()->setClientName($world, $first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;

    expect($world->getServer()->trySetClientName($world, $second, 'Alice'))->toBeFalse();
});

test('trySetClientName returns true when tee reclaims its own name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, 'Alice');
    $world->addTee($tee);

    expect($world->getServer()->trySetClientName($world, $tee, 'Alice'))->toBeTrue();
});
