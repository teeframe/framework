<?php

use TeeFrame\Network\Huffman\Huffman;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;

test('huffman rejects empty and truncated streams without a complete EOF', function () {
    $huffman    = new Huffman;
    $data       = range(0, 255);
    $compressed = $huffman->compress($data);

    for ($length = 0; $length < count($compressed); $length++) {
        expect(fn () => $huffman->decompress(array_slice($compressed, 0, $length)))
            ->toThrow(UnexpectedValueException::class);
    }

    expect($huffman->decompress($compressed))->toBe($data);
});

test('huffman accepts a complete EOF for an empty output', function () {
    $huffman = new Huffman;

    expect($huffman->decompress($huffman->compress([]), outputSize: 0))->toBe([]);
});

test('huffman enforces its output limit before appending another byte', function () {
    $huffman = new Huffman;
    $limit   = NetworkParams::MAXIMUM_PACKET_SIZE - NetworkParams::PACKET_HEADER_SIZE_DEFAULT;
    $data    = array_fill(0, $limit, 0);

    expect($huffman->decompress($huffman->compress($data)))->toBe($data);
    expect(fn () => $huffman->decompress($huffman->compress([...$data, 0])))
        ->toThrow(UnexpectedValueException::class, 'Huffman output exceeds the size limit');
    expect(fn () => $huffman->decompress($huffman->compress([0]), outputSize: 0))
        ->toThrow(UnexpectedValueException::class);
});

test('huffman validates explicit input and output sizes', function (int $size, int $outputSize) {
    expect(fn () => (new Huffman)->decompress([0], $size, $outputSize))
        ->toThrow(UnexpectedValueException::class, 'Invalid Huffman buffer size');
})->with([
    'negative input size'  => [-1, 100],
    'missing input bytes'  => [2, 100],
    'negative output size' => [1, -1],
]);

test('packet decoding rejects malformed or oversized compressed payloads', function () {
    $header     = [NetworkBase::PACKET_FLAG_COMPRESSION << 4, 0, 0];
    $compressed = NetworkBase::compressHuffman(range(0, 255));

    expect(PacketDecoder::decodeFromRaw(NetworkBase::packBuffer($header)))->toBeFalse();
    expect(PacketDecoder::decodeFromRaw(NetworkBase::packBuffer([...$header, ...array_slice($compressed, 0, -1)])))->toBeFalse();
    expect(PacketDecoder::decodeFromRaw(NetworkBase::packBuffer([
        ...$header,
        ...NetworkBase::compressHuffman(array_fill(0, NetworkParams::MAXIMUM_PACKET_SIZE, 0)),
    ])))->toBeFalse();
});

test('huffman round-trips snap-like data correctly', function () {
    // Simulate a small snapshot payload: 3 small ints (removedCount=0, updatedCount=1, 0)
    // plus one character item encoded
    $data = [
        ...NetworkBase::packInt(0),   // removedCount
        ...NetworkBase::packInt(1),   // updatedCount
        ...NetworkBase::packInt(0),   // reserved
        // Character item: itemId + id + 22 ints
        ...NetworkBase::packInt(9),   // NETOBJTYPE_CHARACTER
        ...NetworkBase::packInt(0),   // id=0
        ...NetworkBase::packInt(0),   // tick
        ...NetworkBase::packInt(1584), // x
        ...NetworkBase::packInt(1072), // y
        ...NetworkBase::packInt(0),   // velX
        ...NetworkBase::packInt(0),   // velY
        ...NetworkBase::packInt(0),   // angle
        ...NetworkBase::packInt(1),   // direction
        ...NetworkBase::packInt(0),   // jumped
        ...NetworkBase::packInt(-1),  // hookedPlayer
        ...NetworkBase::packInt(-1),  // hookState
        ...NetworkBase::packInt(0),   // hookTick
        ...NetworkBase::packInt(0),   // hookX
        ...NetworkBase::packInt(0),   // hookY
        ...NetworkBase::packInt(0),   // hookDx
        ...NetworkBase::packInt(0),   // hookDy
        ...NetworkBase::packInt(0),   // playerFlags
        ...NetworkBase::packInt(10),  // health
        ...NetworkBase::packInt(0),   // armor
        ...NetworkBase::packInt(10),  // ammoCount
        ...NetworkBase::packInt(1),   // weapon
        ...NetworkBase::packInt(0),   // emote
        ...NetworkBase::packInt(0),   // attackTick
    ];

    $compressed   = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
});

test('huffman round-trips empty snap payload correctly', function () {
    $data = [
        ...NetworkBase::packInt(0), // removedCount
        ...NetworkBase::packInt(0), // updatedCount
        ...NetworkBase::packInt(0), // reserved
    ];

    $compressed   = NetworkBase::compressHuffman($data);
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

    $compressed   = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
});

test('huffman round-trips same data multiple times consistently', function () {
    $data = [0, 1, 2, 3, 128, 255, 64, 32, 127, 200];

    for ($i = 0; $i < 10; $i++) {
        $compressed   = NetworkBase::compressHuffman($data);
        $decompressed = NetworkBase::decompressHuffman($compressed);

        expect($decompressed)->toBe($data);
    }
});

test('huffman round-trips data matching compressed_size=5 scenario', function () {
    // The client logs show compressed_size=5.
    // This data should be the snap payload (just header: 0, 0, 0)
    $data = [
        ...NetworkBase::packInt(0),
        ...NetworkBase::packInt(0),
        ...NetworkBase::packInt(0),
    ];

    $compressed   = NetworkBase::compressHuffman($data);
    $decompressed = NetworkBase::decompressHuffman($compressed);

    expect($decompressed)->toBe($data);
    // Log the compressed size for debugging
    // fwrite(STDERR, "compressed_size=" . count($compressed) . "\n");
});
