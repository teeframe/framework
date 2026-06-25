<?php

use TeeFrame\Network\Connection\ConnectionSnap;
use TeeFrame\Network\Connection\SnapHandler;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

/**
 * A concrete SnapHandler for testing — exposes protected methods.
 */
class TestSnapHandler extends SnapHandler
{
    public function __construct()
    {
        // Bypass the AbstractConnection dependency by using a mock-like approach
    }

    /**
     * @param  array<int, \TeeFrame\Network\SnapItems\AbstractSnapItem>  $items
     */
    public function testCalculateCrc(array $items): int
    {
        return $this->calculateCrc($items);
    }

    /**
     * @param  array<int, \TeeFrame\Network\SnapItems\AbstractSnapItem>  $items
     * @return array{array<int, int>, int, int}
     */
    public function testCalculateSendablePayload(array $items): array
    {
        return $this->calculateSendablePayload($items);
    }

    /**
     * @param  \TeeFrame\Network\SnapItems\AbstractSnapItem  $deltaItem
     * @param  \TeeFrame\Network\SnapItems\AbstractSnapItem  $item
     * @return int[]
     */
    public function testDiffItem($deltaItem, $item): array
    {
        return $this->diffItem($deltaItem, $item);
    }

    /**
     * @param  \TeeFrame\Network\SnapItems\AbstractSnapItem[]  $items
     * @return array<int, \TeeFrame\Network\SnapItems\AbstractSnapItem>
     */
    public function testIndexItemsList(array $items): array
    {
        return $this->indexItemsList($items);
    }

    public function setDeltaSnap(?ConnectionSnap $snap): void
    {
        $this->deltaSnap = $snap;
    }
}

// --- Test: encode() produces packed type and id ---

test('snap item encode produces packed type and id', function () {
    $item = new ObjProjectileItem(
        x: 1744,
        y: 1072,
        velX: 43,
        velY: 4,
        type: 0,
        startTick: 0,
    );
    $item->setId(2);

    $encoded = $item->encode();

    // The first bytes should be the packed type (2) and packed id (2)
    // packInt(2) = [0x02] (single byte, value 2)
    expect($encoded[0])->toBe(0x02); // packed type=2
    expect($encoded[1])->toBe(0x02); // packed id=2

    // After type+id, the remaining bytes are the packed data ints
    // Verify we can round-trip through variable-int decompression
    $decompressed = decompressVarInts($encoded);
    expect($decompressed[0])->toBe(2);  // type
    expect($decompressed[1])->toBe(2);  // id
    expect($decompressed[2])->toBe(1744); // x
    expect($decompressed[3])->toBe(1072); // y
    expect($decompressed[4])->toBe(43);   // velX
    expect($decompressed[5])->toBe(4);    // velY
    expect($decompressed[6])->toBe(0);    // type (projectile type)
    expect($decompressed[7])->toBe(0);    // startTick
});

// --- Test: removed items use item keys (single packed int) ---

test('delta payload uses item keys for removed items', function () {
    $handler = new TestSnapHandler;

    // Create a delta snapshot with a character item
    $oldChar = new ObjCharacterItem(
        tick: 100, x: 1584, y: 1072,
        velX: 0, velY: 0, angle: 0, direction: 0, jumped: 0,
        hookedPlayer: -1, hookState: -1, hookTick: 0,
        hookX: 0, hookY: 0, hookDx: 0, hookDy: 0,
        playerFlags: 0, health: 10, armor: 0, ammoCount: 10,
        weapon: 1, emote: 0, attackTick: 0,
    );
    $oldChar->setId(1);

    $oldItems = $handler->testIndexItemsList([$oldChar]);
    $handler->setDeltaSnap(new ConnectionSnap(100, $oldItems, 0));

    // Current items: empty (character was removed)
    $currentItems = $handler->testIndexItemsList([]);

    [$payload, $removedCount, $updatedCount] = $handler->testCalculateSendablePayload($currentItems);

    // Should have 1 removed item, 0 updated
    expect($removedCount)->toBe(1);
    expect($updatedCount)->toBe(0);

    // The removed item should be the item key packed as variable int
    // key = (9 << 16) | 1 = 589825
    $expectedKey = (9 << 16) | 1;
    expect($expectedKey)->toBe(589825);

    // Decompress the payload to verify it contains the key
    $decompressed = decompressVarInts($payload);
    expect($decompressed)->toHaveCount(1);
    expect($decompressed[0])->toBe($expectedKey);
});

// --- Test: diffItem produces packed type and id ---

test('diffItem produces packed type and id', function () {
    $handler = new TestSnapHandler;

    $oldProj = new ObjProjectileItem(
        x: 1700, y: 1068, velX: 0, velY: 0, type: 0, startTick: 0,
    );
    $oldProj->setId(2);

    $newProj = new ObjProjectileItem(
        x: 1744, y: 1072, velX: 43, velY: 4, type: 0, startTick: 0,
    );
    $newProj->setId(2);

    $diff = $handler->testDiffItem($oldProj, $newProj);

    // Decompress to verify format: [type, id, diff_x, diff_y, diff_velX, diff_velY, diff_type, diff_startTick]
    $decompressed = decompressVarInts($diff);

    expect($decompressed[0])->toBe(2);    // type (NETOBJTYPE_PROJECTILE)
    expect($decompressed[1])->toBe(2);    // id
    expect($decompressed[2])->toBe(44);   // diff x: 1744 - 1700
    expect($decompressed[3])->toBe(4);    // diff y: 1072 - 1068
    expect($decompressed[4])->toBe(43);   // diff velX: 43 - 0
    expect($decompressed[5])->toBe(4);    // diff velY: 4 - 0
    expect($decompressed[6])->toBe(0);    // diff type
    expect($decompressed[7])->toBe(0);    // diff startTick
});

// --- Test: full delta payload matches libtw2 format ---

test('full delta payload matches libtw2 snapshot delta format', function () {
    $handler = new TestSnapHandler;

    // --- Build the "old" snapshot (delta base) ---
    $oldChar = new ObjCharacterItem(
        tick: 100, x: 1584, y: 1072,
        velX: 0, velY: 0, angle: 0, direction: 0, jumped: 0,
        hookedPlayer: -1, hookState: -1, hookTick: 0,
        hookX: 0, hookY: 0, hookDx: 0, hookDy: 0,
        playerFlags: 0, health: 10, armor: 0, ammoCount: 10,
        weapon: 1, emote: 0, attackTick: 0,
    );
    $oldChar->setId(1);

    $oldProj = new ObjProjectileItem(
        x: 1700, y: 1068, velX: 0, velY: 0, type: 0, startTick: 0,
    );
    $oldProj->setId(2);

    $oldItems = $handler->testIndexItemsList([$oldChar, $oldProj]);
    $handler->setDeltaSnap(new ConnectionSnap(100, $oldItems, 0));

    // --- Build the "new" snapshot ---
    // Character removed, projectile updated
    $newProj = new ObjProjectileItem(
        x: 1744, y: 1072, velX: 43, velY: 4, type: 0, startTick: 0,
    );
    $newProj->setId(2);

    $newItems = $handler->testIndexItemsList([$newProj]);

    [$payload, $removedCount, $updatedCount] = $handler->testCalculateSendablePayload($newItems);

    expect($removedCount)->toBe(1);
    expect($updatedCount)->toBe(1);

    // Build the full delta payload as the server would:
    // [packed(removedCount), packed(updatedCount), packed(0), ...payload]
    $fullPayload = [
        ...NetworkBase::packInt($removedCount),
        ...NetworkBase::packInt($updatedCount),
        ...NetworkBase::packInt(0),
        ...$payload,
    ];

    // Decompress to verify the full structure
    $decompressed = decompressVarInts($fullPayload);

    // Expected: [1, 1, 0, removedKey, type, id, diff_x, diff_y, diff_velX, diff_velY, diff_type, diff_startTick]
    expect($decompressed[0])->toBe(1);       // num_removed_items
    expect($decompressed[1])->toBe(1);       // num_item_deltas
    expect($decompressed[2])->toBe(0);       // _zero

    // Removed item key: (9 << 16) | 1 = 589825
    expect($decompressed[3])->toBe(589825);  // removed item key

    // Updated item: type=2, id=2, then 6 diff ints
    expect($decompressed[4])->toBe(2);       // type_id
    expect($decompressed[5])->toBe(2);       // id
    expect($decompressed[6])->toBe(44);      // diff x
    expect($decompressed[7])->toBe(4);       // diff y
    expect($decompressed[8])->toBe(43);      // diff velX
    expect($decompressed[9])->toBe(4);       // diff velY
    expect($decompressed[10])->toBe(0);      // diff type
    expect($decompressed[11])->toBe(0);      // diff startTick

    expect($decompressed)->toHaveCount(12);
});

// --- Test: CRC is computed from getInts(), matching client-side Crc() ---

test('snap crc matches client-side Crc computation', function () {
    $handler = new TestSnapHandler;

    $char = new ObjCharacterItem(
        tick: 100, x: 1584, y: 1072,
        velX: 0, velY: 128, angle: 0, direction: 0, jumped: 0,
        hookedPlayer: -1, hookState: 0, hookTick: 0,
        hookX: 1584, hookY: 304, hookDx: 0, hookDy: 0,
        playerFlags: 0, health: 10, armor: 0, ammoCount: 10,
        weapon: 1, emote: 0, attackTick: 0,
    );
    $char->setId(0);

    $proj = new ObjProjectileItem(
        x: 1744, y: 1072, velX: 43, velY: 4, type: 0, startTick: 0,
    );
    $proj->setId(2);

    $items = $handler->testIndexItemsList([$char, $proj]);
    $crc = $handler->testCalculateCrc($items);

    // The CRC should be the sum of all getInts() values, wrapped to int32
    $expectedSum = 0;
    foreach ([$char, $proj] as $item) {
        foreach ($item->getInts() as $int) {
            $expectedSum += $int;
        }
    }
    $expectedCrc = NetworkBase::toInt32($expectedSum);

    expect($crc)->toBe($expectedCrc);
});

// --- Helper: simulate CVariableInt::Decompress ---

/**
 * Simulate the client-side CVariableInt::Decompress:
 * takes packed bytes and returns the decompressed int32 array.
 *
 * @param  int[]  $bytes
 * @return int[]
 */
function decompressVarInts(array $bytes): array
{
    $result = [];
    $i = 0;
    $len = count($bytes);

    while ($i < $len) {
        [$value, $consumed] = NetworkBase::unpackInt(array_slice($bytes, $i));
        $result[] = $value;
        $i += $consumed;
    }

    return $result;
}