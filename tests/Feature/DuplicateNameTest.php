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

test('name uniqueness checks the bounded UTF-8 name including generated prefixes', function () {
    $world  = createWorld(new Map);
    $first  = new PlayerTee;
    $second = new PlayerTee;
    $third  = new PlayerTee;
    $world->setTeeName($first, str_repeat('é', 20).'first');
    $world->addTee($first);
    $world->setTeeName($second, str_repeat('é', 20).'second');
    $world->addTee($second);

    expect($first->name)->toBe(str_repeat('é', 7));
    expect($second->name)->toBe('(1)'.str_repeat('é', 6));
    expect($world->trySetTeeName($third, str_repeat('é', 20).'third'))->toBeFalse();
    expect($third->name)->toBe('');
    $world->setTeeName($third, str_repeat('é', 20).'third');
    expect($third->name)->toBe('(2)'.str_repeat('é', 6));
});

test('setTeeName sets the name when no collision', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->setTeeName($tee, 'Alice');

    expect($tee->name)->toBe('Alice');
});

test('setTeeName trims whitespace from the name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->setTeeName($tee, '  Bob  ');

    expect($tee->name)->toBe('Bob');
});

test('setTeeName generates a fallback name for blank input', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->setTeeName($tee, '   ');

    expect($tee->name)->toBe('(1)');
});

test('setTeeName auto-renames on duplicate to (1)name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->setTeeName($first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;
    $world->setTeeName($second, 'Alice');

    expect($first->name)->toBe('Alice');
    expect($second->name)->toBe('(1)Alice');
});

test('setTeeName uses incrementing prefixes for multiple duplicates', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->setTeeName($first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;
    $world->setTeeName($second, 'Alice');
    $world->addTee($second);

    $third = new PlayerTee;
    $world->setTeeName($third, 'Alice');

    expect($first->name)->toBe('Alice');
    expect($second->name)->toBe('(1)Alice');
    expect($third->name)->toBe('(2)Alice');
});

test('setTeeName does not rename when the tee keeps its own name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->setTeeName($tee, 'Alice');
    $world->addTee($tee);

    // Re-setting the same name on the same tee should not trigger auto-rename.
    $world->setTeeName($tee, 'Alice');

    expect($tee->name)->toBe('Alice');
});

test('setTeeName cleans control characters from the name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    // \x01 is a control character (< 32), should be replaced with space, then trimmed.
    $world->setTeeName($tee, "\x01Alice\x01");

    expect($tee->name)->toBe('Alice');
});

test('trySetTeeName returns false for empty name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;

    expect($world->trySetTeeName($tee, ''))->toBeFalse();
    expect($world->trySetTeeName($tee, '   '))->toBeFalse();
});

test('trySetTeeName returns false when name is taken by another tee', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $first = new PlayerTee;
    $world->setTeeName($first, 'Alice');
    $world->addTee($first);

    $second = new PlayerTee;

    expect($world->trySetTeeName($second, 'Alice'))->toBeFalse();
});

test('trySetTeeName returns true when tee reclaims its own name', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map   = new Map($mapPath);
    $world = createWorld($map);

    $tee = new PlayerTee;
    $world->setTeeName($tee, 'Alice');
    $world->addTee($tee);

    expect($world->trySetTeeName($tee, 'Alice'))->toBeTrue();
});
