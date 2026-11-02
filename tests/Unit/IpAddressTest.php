<?php

use TeeFrame\Core\IpAddress;

test('IP normalization preserves IPv4 and canonicalizes equivalent IPv6 addresses', function (string $address, string $expected) {
    expect(IpAddress::normalize($address))->toBe($expected);
})->with([
    ['192.0.2.1', '192.0.2.1'],
    ['::ffff:192.0.2.1', '192.0.2.1'],
    ['0:0:0:0:0:ffff:c000:201', '192.0.2.1'],
    ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1'],
    ['2001:db8::1', '2001:db8::1'],
    ['0:0:0:0:0:0:0:1', '::1'],
]);

test('IP normalization rejects non-addresses without accepting ports or hostnames', function (string $address) {
    expect(IpAddress::normalize($address))->toBeNull();
})->with(['', 'localhost', '192.0.2.1:8303', '[::1]', '2001:db8::xyz', '999.0.0.1', "192.0.2.1\0", '1']);
