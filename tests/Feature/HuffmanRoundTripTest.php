<?php

use TeeFrame\Network\NetworkBase;

test('huffman round-trips snap-like data correctly', function () {
    // Simulate a small snapshot payload: 3 small ints (removedCount=0, updatedCount=1, 0)
    // plus one character item encoded
    $data = [
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // removedCount
        ...\TeeFrame\Network\NetworkBase::packInt(1),   // updatedCount
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // reserved
        // Character item: itemId + id + 22 ints
        ...\TeeFrame\Network\NetworkBase::packInt(9),   // NETOBJTYPE_CHARACTER
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // id=0
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // tick
        ...\TeeFrame\Network\NetworkBase::packInt(1584), // x
        ...\TeeFrame\Network\NetworkBase::packInt(1072), // y
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // velX
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // velY
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // angle
        ...\TeeFrame\Network\NetworkBase::packInt(1),   // direction
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // jumped
        ...\TeeFrame\Network\NetworkBase::packInt(-1),  // hookedPlayer
        ...\TeeFrame\Network\NetworkBase::packInt(-1),  // hookState
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // hookTick
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // hookX
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // hookY
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // hookDx
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // hookDy
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // playerFlags
        ...\TeeFrame\Network\NetworkBase::packInt(10),  // health
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // armor
        ...\TeeFrame\Network\NetworkBase::packInt(10),  // ammoCount
        ...\TeeFrame\Network\NetworkBase::packInt(1),   // weapon
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // emote
        ...\TeeFrame\Network\NetworkBase::packInt(0),   // attackTick
    ];

    $compressed = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
});

test('huffman round-trips empty snap payload correctly', function () {
    $data = [
        ...\TeeFrame\Network\NetworkBase::packInt(0), // removedCount
        ...\TeeFrame\Network\NetworkBase::packInt(0), // updatedCount
        ...\TeeFrame\Network\NetworkBase::packInt(0), // reserved
    ];

    $compressed = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
});

test('huffman round-trips clientinfo-like data correctly', function () {
    // Simulate packed client info bytes (all within 0-255 range)
    $data = [
        0, 1, 2, 3, 4, 5,  // random bytes
        128, 255, 0, 127,     // edge values
        64, 32, 16, 8, 4, 2, 1, // more bytes
    ];

    $compressed = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
});

test('huffman round-trips same data multiple times consistently', function () {
    $data = [0, 1, 2, 3, 128, 255, 64, 32, 127, 200];

    for ($i = 0; $i < 10; $i++) {
        $compressed = NetworkBase::compressHuffman($data);
        $decompressed = NetworkBase::decompressHuffman($compressed);

        expect($decompressed)->toBe($data);
    }
});

test('huffman round-trips data matching compressed_size=5 scenario', function () {
    // The client logs show compressed_size=5.
    // This data should be the snap payload (just header: 0, 0, 0)
    $data = [
        ...\TeeFrame\Network\NetworkBase::packInt(0),
        ...\TeeFrame\Network\NetworkBase::packInt(0),
        ...\TeeFrame\Network\NetworkBase::packInt(0),
    ];

    $compressed = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
    // Log the compressed size for debugging
    // fwrite(STDERR, "compressed_size=" . count($compressed) . "\n");
});