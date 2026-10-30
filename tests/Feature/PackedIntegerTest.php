<?php

use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\RawPayload;

test('packed integers preserve signed values and consume only their own bytes', function (int $value, array $bytes) {
    expect(NetworkBase::packInt($value))->toBe($bytes);
    expect(NetworkBase::unpackInt([...$bytes, 42]))->toBe([$value, count($bytes)]);
})->with([
    'zero'                  => [0, [0]],
    'negative one'          => [-1, [0x40]],
    'one byte maximum'      => [63, [0x3F]],
    'one byte minimum'      => [-64, [0x7F]],
    'two byte positive'     => [64, [0x80, 1]],
    'two byte negative'     => [-65, [0xC0, 1]],
    'two byte maximum'      => [8191, [0xBF, 0x7F]],
    'three byte positive'   => [8192, [0x80, 0x80, 1]],
    'three byte maximum'    => [1048575, [0xBF, 0xFF, 0x7F]],
    'four byte positive'    => [1048576, [0x80, 0x80, 0x80, 1]],
    'four byte maximum'     => [134217727, [0xBF, 0xFF, 0xFF, 0x7F]],
    'five byte positive'    => [134217728, [0x80, 0x80, 0x80, 0x80, 1]],
    'signed 32 bit maximum' => [2147483647, [0xBF, 0xFF, 0xFF, 0xFF, 0x0F]],
    'signed 32 bit minimum' => [-2147483648, [0xFF, 0xFF, 0xFF, 0xFF, 0x0F]],
]);

test('packed integer decoding rejects missing bytes without PHP warnings', function (array $bytes) {
    expect(fn () => NetworkBase::unpackInt($bytes))->toThrow(UnexpectedValueException::class);
})->with([
    'empty'                        => [[]],
    'missing second byte'          => [[0x80]],
    'missing third byte'           => [[0x80, 0x80]],
    'missing fourth byte'          => [[0x80, 0x80, 0x80]],
    'missing fifth byte'           => [[0x80, 0x80, 0x80, 0x80]],
    'negative missing second byte' => [[0xC0]],
    'negative missing fifth byte'  => [[0xC0, 0x80, 0x80, 0x80]],
]);

test('packed integer decoding rejects values outside the signed 32 bit format', function (array $bytes) {
    expect(fn () => NetworkBase::unpackInt($bytes))->toThrow(UnexpectedValueException::class);
})->with([
    'positive overflow'          => [[0x80, 0x80, 0x80, 0x80, 0x10]],
    'negative overflow'          => [[0xC0, 0x80, 0x80, 0x80, 0x10]],
    'continuation on fifth byte' => [[0x80, 0x80, 0x80, 0x80, 0x80]],
    'six byte integer'           => [[0x80, 0x80, 0x80, 0x80, 0x80, 0]],
]);

test('required payload integers cannot silently default to zero', function () {
    expect(fn () => (new RawPayload)->extractInt())->toThrow(UnexpectedValueException::class);
    expect(fn () => (new RawPayload)->extractBool())->toThrow(UnexpectedValueException::class);
});

test('failed packed integer reads leave the payload unchanged', function () {
    $bytes   = [0x80, 0x80];
    $payload = new RawPayload($bytes);

    expect(fn () => $payload->extractInt())->toThrow(UnexpectedValueException::class);
    expect($payload->encode())->toBe($bytes);
});

test('optional payload integers default only when no bytes are present', function () {
    expect((new RawPayload)->extractInt(throw: false))->toBe(0);
    expect(fn () => (new RawPayload([0x80]))->extractInt(throw: false))->toThrow(UnexpectedValueException::class);
});

test('zero six vote requests encode and decode exactly three strings', function () {
    $payload = (new RawPayload)
        ->addString('option')
        ->addString('Reload')
        ->addString('Testing');

    $chunk = ClCallVoteChunk::make($payload);

    expect($chunk->type)->toBe('option');
    expect($chunk->value)->toBe('Reload');
    expect($chunk->reason)->toBe('Testing');
    expect($payload->encode())->toBeEmpty();
    expect($chunk->getPayload()->encode())->toBe(NetworkBase::unpackBuffer("option\0Reload\0Testing\0"));
});
