<?php

use Network\SnapItems\ObjClientInfoItem;

test('stringToIntegers', function () {
    $snap     = new ObjClientInfoItem("wL7SHc4Ipa1prqHE", "", 0, "", false, 0, 0);
    $snapInts = $snap->getInts();    

    expect(array_slice($snapInts, 0, 4))->toBe([
        -137578541,
        -924601143,
        -253644304,
        -219035648,
    ]);
});
