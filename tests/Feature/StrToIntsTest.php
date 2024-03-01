<?php

use Network\NetworkBase;
use Network\SnapItems\ObjClientInfoItem;

function createClientInfoItem(string $name) {
    $clan           = 'kj';
    $country        = -1;
    $skinName       = 'default';
    $useCustomColor = false;
    $colorBody      = 65408;
    $colorFeet      = 65408;

    return new ObjClientInfoItem($name, $clan, $country, $skinName, $useCustomColor, $colorBody, $colorFeet);
}

test('can encode client info name to correct integers "wL7SHc4Ipa1prqHE"', function () {
    $snapInts = createClientInfoItem("wL7SHc4Ipa1prqHE")->getInts();

    $nameInts = array_slice($snapInts, 0, 4);

    expect($nameInts)->toBe([
        -137578541,
        -924601143,
        -253644304,
        -219035648,
    ]);
});

test('can encode full client info to correct integers "aaaaaaaaaaaaaaa"', function() {
    $snapInts = createClientInfoItem("aaaaaaaaaaaaaaa")->getInts();

    expect($snapInts)->toBe([
        // Name
        -505290271,
        -505290271,
        -505290271,
        -505290496,
        // Clan
        -336953216,
        -2139062144,
        -2139062272,
        // Country
        -1,
        // Skin
        -454695199,
        -169020288,
        -2139062144,
        -2139062144,
        -2139062144,
        -2139062272,
        // Use Custom Color
        0,
        // Color Body
        65408,
        // Color Feet
        65408,
    ]);
});

test('can encode full client info to correct bytes "aaaaaaaaaaaaaaa"', function() {
    $snapBytes = createClientInfoItem("aaaaaaaaaaaaaaa")->encode();

    expect($snapBytes)->toBe(
        NetworkBase::unpackBuffer("\x0b\x00\xde\xf8\xf0\xe1\x03\xde\xf8\xf0\xe1\x03\xde\xf8\xf0\xe1\x03\xff\xfb\xf0\xe1\x03\xff\xfd\xab\xc1\x02\xff\xfd\xfb\xf7\x0f\xff\xff\xfb\xf7\x0f\x40\xde\xe4\xd0\xb1\x03\xff\xad\x98\xa1\x01\xff\xfd\xfb\xf7\x0f\xff\xfd\xfb\xf7\x0f\xff\xfd\xfb\xf7\x0f\xff\xff\xfb\xf7\x0f\x00\x80\xfe\x07\x80\xfe\x07")
    );
});