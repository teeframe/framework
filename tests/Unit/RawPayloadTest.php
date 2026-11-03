<?php

use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\RawPayload;

test('string extraction preserves byte values and the remaining fields', function (string $text) {
    $payload = (new RawPayload)->addString($text)->addInt(-129)->addString('next');

    expect($payload->extractString())->toBe($text);
    expect($payload->extractInt())->toBe(-129);
    expect($payload->extractString())->toBe('next');
    expect($payload->encode())->toBeEmpty();
})->with([
    'empty'             => '',
    'ASCII'             => 'hello',
    'UTF-8'             => 'Olá 世界 👋',
    'all nonzero bytes' => implode('', array_map(chr(...), range(1, 255))),
    'long string'       => str_repeat('a', 1021),
]);

test('string extraction stops at the first zero and leaves subsequent zeroes intact', function () {
    $payload = new RawPayload([65, 0, 0, 66, 0, ...NetworkBase::packInt(1234)]);

    expect($payload->extractString())->toBe('A');
    expect($payload->extractString())->toBe('');
    expect($payload->extractString())->toBe('B');
    expect($payload->extractInt())->toBe(1234);
    expect($payload->encode())->toBeEmpty();
});

test('unterminated strings still throw and consume the malformed payload', function (array $bytes) {
    /** @var int[] $bytes */
    $payload = new RawPayload($bytes);

    expect(fn () => $payload->extractString())->toThrow(UnexpectedValueException::class, 'Unterminated string in payload');
    expect($payload->encode())->toBeEmpty();
})->with([
    'missing'     => [[]],
    'ASCII'       => [[65, 66]],
    'high bytes'  => [[255, 128]],
    'long string' => [array_fill(0, 1021, 65)],
]);
