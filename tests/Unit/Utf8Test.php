<?php

use TeeFrame\Core\Utf8;

test('UTF-8 byte truncation keeps complete characters within the byte budget', function (int $limit, string $expected) {
    $result = Utf8::truncateBytes('A🎮é中Z', $limit);

    expect($result)->toBe($expected);
    expect(strlen($result))->toBeLessThanOrEqual($limit);
    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
})->with([
    [0, ''],
    [1, 'A'],
    [2, 'A'],
    [3, 'A'],
    [4, 'A'],
    [5, 'A🎮'],
    [6, 'A🎮'],
    [7, 'A🎮é'],
    [8, 'A🎮é'],
    [9, 'A🎮é'],
    [10, 'A🎮é中'],
    [11, 'A🎮é中Z'],
    [12, 'A🎮é中Z'],
]);

test('UTF-8 byte truncation preserves short and ASCII strings', function () {
    expect(Utf8::truncateBytes('', 0))->toBe('');
    expect(Utf8::truncateBytes('text', 8))->toBe('text');
    expect(Utf8::truncateBytes(' text ', 5))->toBe(' text');
});

test('UTF-8 byte truncation rejects a negative byte limit', function () {
    expect(fn () => Utf8::truncateBytes('text', -1))->toThrow(InvalidArgumentException::class);
});
