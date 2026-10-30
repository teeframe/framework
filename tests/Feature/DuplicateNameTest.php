<?php

use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

test('world-owned names are unique only within that world', function () {
    $firstWorld  = createWorld(new Map);
    $secondWorld = createWorld(new Map);
    $first       = new PlayerTee;
    $second      = new PlayerTee;
    $firstWorld->setTeeName($first, "\x01Alice\x01");
    $secondWorld->setTeeName($second, 'Alice');
    $firstWorld->addTee($first);
    $secondWorld->addTee($second);
    $duplicate       = new PlayerTee;
    $duplicate->name = 'Unchanged';

    expect($first->name)->toBe('Alice');
    expect($second->name)->toBe('Alice');
    expect($firstWorld->trySetTeeName($duplicate, 'Alice'))->toBeFalse();
    expect($duplicate->name)->toBe('Unchanged');
    $firstWorld->setTeeName($duplicate, 'Alice');
    expect($duplicate->name)->toBe('(1)Alice');
    expect($secondWorld->trySetTeeName($second, 'Alice'))->toBeTrue();
});

test('world name validation preserves case sensitivity and replaces embedded controls', function () {
    $world = createWorld(new Map);
    $first = new PlayerTee;
    $world->setTeeName($first, 'Alice');
    $world->addTee($first);
    $second = new PlayerTee;
    $world->setTeeName($second, 'alice');
    expect($second->name)->toBe('alice');
    $world->setTeeName($second, "A\x01B");
    expect($second->name)->toBe('A B');
    expect($world->trySetTeeName($second, '   '))->toBeFalse();
    expect($second->name)->toBe('A B');
    $world->setTeeName($second, "\0\t");
    expect($second->name)->toBe('(1)');
});

test('setClientName sets the name when no collision', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, 'Alice');

    expect($tee->name)->toBe('Alice');
});

test('setClientName trims whitespace from the name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, '  Bob  ');

    expect($tee->name)->toBe('Bob');
});

test('setClientName rejects empty name and keeps it empty', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, '   ');

    // Empty after trim: trySetClientName returns false, auto-rename loop also
    // fails because "(1)" + "" = "(1)" which is non-empty, so it should resolve.
    expect($tee->name)->toBe('(1)');
});

test('setClientName auto-renames on duplicate to (1)name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
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
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
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
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
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
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    // \x01 is a control character (< 32), should be replaced with space, then trimmed.
    $world->getServer()->setClientName($world, $tee, "\x01Alice\x01");

    expect($tee->name)->toBe('Alice');
});

test('trySetClientName returns false for empty name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;

    expect($world->getServer()->trySetClientName($world, $tee, ''))->toBeFalse();
    expect($world->getServer()->trySetClientName($world, $tee, '   '))->toBeFalse();
});

test('trySetClientName returns false when name is taken by another tee', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->getServer()->setClientName($world, $first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;

    expect($world->getServer()->trySetClientName($world, $second, 'Alice'))->toBeFalse();
});

test('trySetClientName returns true when tee reclaims its own name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->getServer()->setClientName($world, $tee, 'Alice');
    $world->addTee($tee);

    expect($world->getServer()->trySetClientName($world, $tee, 'Alice'))->toBeTrue();
});
