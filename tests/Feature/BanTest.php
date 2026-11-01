<?php

use TeeFrame\Server\Ban\Ban;
use TeeFrame\Server\Ban\BanList;

test('BanList bans an address', function () {
    $banList = new BanList;

    $banList->ban('192.168.1.1', 600, 'Kicked by vote');

    expect($banList->isBanned('192.168.1.1'))->toBeTrue();
    expect($banList->isBanned('192.168.1.2'))->toBeFalse();
});

test('BanList getBan returns the ban entry', function () {
    $banList = new BanList;

    $banList->ban('10.0.0.1', 300, 'Test reason');

    $ban = $banList->getBan('10.0.0.1');
    assert($ban instanceof Ban);
    expect($ban->address)->toBe('10.0.0.1');
    expect($ban->reason)->toBe('Test reason');
    expect($ban->expiry)->toBeGreaterThan(time());
});

test('BanList getBan returns null for non-banned address', function () {
    $banList = new BanList;

    expect($banList->getBan('10.0.0.1'))->toBeNull();
});

test('BanList removes expired bans on cleanup', function () {
    $banList = new BanList;

    // Ban for 0 seconds — immediately expired
    $banList->ban('10.0.0.2', 0, 'Expired');

    // isBanned triggers cleanup
    expect($banList->isBanned('10.0.0.2'))->toBeFalse();
});

test('permanent bans do not expire and IPv6 lookups use a canonical address', function () {
    $banList = new BanList;
    $banList->ban('2001:db8:0:0:0:0:0:1', null, 'Permanent');
    expect($banList->isBanned('2001:db8::1'))->toBeTrue();
    $ban = $banList->getBan('2001:db8::1');
    assert($ban instanceof Ban);
    expect($ban->expiry)->toBeNull();
    expect($ban->isExpired(PHP_INT_MAX))->toBeFalse();
    $banList->unban('2001:db8:0:0:0:0:0:1');
    expect($banList->getBans())->toBeEmpty();
});

test('shared IP normalization preserves BanList handling of non-IP keys', function () {
    $bans = new BanList;
    $bans->ban('legacy-key', 60, 'Existing API behavior');
    expect($bans->isBanned('legacy-key'))->toBeTrue();
    expect($bans->getBan('legacy-key')?->address)->toBe('legacy-key');
    $bans->unban('legacy-key');
    expect($bans->isBanned('legacy-key'))->toBeFalse();
});

test('Ban isExpired returns true for past expiry', function () {
    $ban = new Ban('1.2.3.4', time() - 100, 'Past');

    expect($ban->isExpired(time()))->toBeTrue();
});

test('Ban isExpired returns false for future expiry', function () {
    $ban = new Ban('1.2.3.4', time() + 100, 'Future');

    expect($ban->isExpired(time()))->toBeFalse();
});
