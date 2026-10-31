<?php

use TeeFrame\Core\Utf8;

test('UTF-8 length counts characters instead of bytes', function (string $text, int $expected) {
    expect(Utf8::length($text))->toBe($expected);
})->with([
    ['', 0],
    ['text', 4],
    [' text ', 6],
    ['é', 1],
    ['中', 1],
    ['🎮', 1],
    ['A🎮é中Z', 5],
]);

test('UTF-8 character truncation counts characters instead of bytes', function (int $limit, string $expected) {
    $result = Utf8::truncateCharacters('A🎮é中Z', $limit);

    expect($result)->toBe($expected);
    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
})->with([
    [0, ''],
    [1, 'A'],
    [2, 'A🎮'],
    [3, 'A🎮é'],
    [4, 'A🎮é中'],
    [5, 'A🎮é中Z'],
    [6, 'A🎮é中Z'],
]);

test('UTF-8 character truncation preserves empty strings and whitespace', function () {
    expect(Utf8::truncateCharacters('', 0))->toBe('');
    expect(Utf8::truncateCharacters(' text ', 10))->toBe(' text ');
    expect(Utf8::truncateCharacters(' text ', 5))->toBe(' text');
});

test('UTF-8 character truncation rejects a negative character limit', function () {
    expect(fn () => Utf8::truncateCharacters('text', -1))->toThrow(InvalidArgumentException::class);
});

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
