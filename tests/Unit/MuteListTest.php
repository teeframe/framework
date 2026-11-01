<?php

use TeeFrame\Server\Chat\MuteList;

test('mutes use canonical IPv6 addresses and can be removed with another spelling', function () {
    $mutes = new MuteList;
    $mutes->mute('2001:db8:0:0:0:0:0:1', 60, 'Spam');
    expect($mutes->getMute('2001:db8::1')['reason'] ?? null)->toBe('Spam');
    expect(array_keys($mutes->getMutes()))->toBe(['2001:db8::1']);
    $mutes->unmute('2001:db8:0:0:0:0:0:1');
    expect($mutes->getMutes())->toBeEmpty();
});

test('shared IP normalization preserves strict validation for mute operations', function () {
    $mutes = new MuteList;
    expect(fn () => $mutes->mute('not-an-ip', 60, 'Test'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mutes->getMute('not-an-ip'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mutes->unmute('not-an-ip'))->toThrow(InvalidArgumentException::class);
    expect($mutes->getMutes())->toBeEmpty();
});

test('expired mutes are discarded before chat and list lookups', function () {
    $mutes    = new MuteList;
    $property = new ReflectionProperty($mutes, 'mutes');
    $property->setValue($mutes, [
        '192.0.2.1' => ['expiry' => time() - 1, 'reason' => 'Expired'],
        '192.0.2.2' => ['expiry' => time() + 60, 'reason' => 'Active'],
    ]);
    expect($mutes->getMute('192.0.2.1'))->toBeNull();
    expect(array_keys($mutes->getMutes()))->toBe(['192.0.2.2']);
});

test('invalid mute durations leave the mute list untouched', function (int $seconds) {
    $mutes = new MuteList;
    expect(fn () => $mutes->mute('192.0.2.1', $seconds, 'Spam'))->toThrow(InvalidArgumentException::class);
    expect($mutes->getMutes())->toBeEmpty();
})->with([-1, 0, 86401, PHP_INT_MAX]);
