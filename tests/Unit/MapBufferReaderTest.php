<?php

use TeeFrame\Map\MapBufferReader;

test('map buffer cursor supports interleaved byte integer and string reads', function () {
    $bytes  = 'DATA'.pack('V', -2147483648)."\0hello\0".pack('V*', 1, 0xFFFFFFFF).'tail';
    $reader = new MapBufferReader($bytes);

    expect($reader->remaining())->toBe(strlen($bytes));
    expect($reader->readMagic())->toBe('DATA');
    expect($reader->remaining())->toBe(strlen($bytes) - 4);
    expect($reader->readInt())->toBe(-2147483648);
    expect($reader->readString())->toBe('');
    expect($reader->readString())->toBe('hello');
    expect($reader->readInts(2))->toBe([1, -1]);
    expect($reader->remaining())->toBe(4);
    expect($reader->readBytes(4))->toBe('tail');
    expect($reader->remaining())->toBe(0);
    expect($reader->readBytes(0))->toBe('');
    expect($reader->readInts(0))->toBe([]);
});

test('map buffer strings begin at the cursor and consume exactly one terminator', function () {
    $reader = new MapBufferReader("prefix\0é🎮\0\0tail");
    expect($reader->readBytes(7))->toBe("prefix\0");
    expect($reader->readString())->toBe('é🎮');
    expect($reader->readString())->toBe('');
    expect($reader->readBytes(4))->toBe('tail');
    expect($reader->remaining())->toBe(0);
});

test('unterminated map strings consume the remaining bytes before failing', function (string $bytes, int $prefixLength) {
    $reader = new MapBufferReader($bytes);
    $reader->readBytes($prefixLength);

    expect(fn () => $reader->readString())->toThrow(UnexpectedValueException::class, 'Unterminated map string');
    expect($reader->remaining())->toBe(0);
    expect($reader->readBytes(0))->toBe('');
})->with([
    ['unterminated', 0],
    ["prefix\0unterminated", 7],
    ['', 0],
    ['prefix', 6],
]);

test('failed map reads leave the cursor unchanged', function () {
    $reader = new MapBufferReader('prefixDATA');
    expect($reader->readBytes(6))->toBe('prefix');

    expect(fn () => $reader->readBytes(-1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->readInts(-1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->readBytes(5))->toThrow(UnexpectedValueException::class);
    expect(fn () => $reader->readInts(2))->toThrow(UnexpectedValueException::class);
    expect($reader->remaining())->toBe(4);
    expect($reader->readMagic())->toBe('DATA');
    expect($reader->remaining())->toBe(0);
    expect(fn () => $reader->readInt())->toThrow(UnexpectedValueException::class);
    expect(fn () => $reader->readMagic())->toThrow(UnexpectedValueException::class);
});

test('bulk map integer reads leave a large trailing data section intact', function () {
    $values = range(-1500, 1499);
    $tail   = str_repeat("\x01\x02\x03\x04", 1024 * 1024);
    $reader = new MapBufferReader(pack('V*', ...$values).$tail);

    expect($reader->readInts(count($values)))->toBe($values);
    expect($reader->remaining())->toBe(strlen($tail));
    expect($reader->readBytes(strlen($tail)))->toBe($tail);
    expect($reader->remaining())->toBe(0);
});
