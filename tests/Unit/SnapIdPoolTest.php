<?php

use TeeFrame\Game\World\SnapIdPool;

test('snapshot ids are reused in release order from the top of the stack including zero', function () {
    $pool = new SnapIdPool(maximumIdNumber: 3);
    expect([$pool->allocId(), $pool->allocId(), $pool->allocId(), $pool->allocId()])->toBe([0, 1, 2, 3]);
    expect(fn () => $pool->allocId())->toThrow(RuntimeException::class, 'No more snap ids available');
    $pool->freeId(2);
    $pool->freeId(0);
    $pool->freeId(3);
    expect([$pool->allocId(), $pool->allocId(), $pool->allocId()])->toBe([3, 0, 2]);
    expect(fn () => $pool->allocId())->toThrow(RuntimeException::class, 'No more snap ids available');
});

test('snapshot id membership follows reuse and still rejects duplicate frees', function (int $id) {
    $pool = new SnapIdPool;
    for ($i = 0; $i <= $id; $i++) {
        $pool->allocId();
    }
    $pool->freeId($id);
    expect(fn () => $pool->freeId($id))->toThrow(RuntimeException::class, 'Trying to free an unallocated snap id');
    expect($pool->allocId())->toBe($id);
    $pool->freeId($id);
    expect($pool->allocId())->toBe($id);
    expect($pool->allocId())->toBe($id + 1);
})->with([0, 1, 255]);

test('snapshot ids that have not been allocated cannot be freed', function () {
    $pool = new SnapIdPool;
    expect(fn () => $pool->freeId(0))->toThrow(RuntimeException::class, 'Trying to free an unallocated snap id');
    expect($pool->allocId())->toBe(0);
    expect(fn () => $pool->freeId(1))->toThrow(RuntimeException::class, 'Trying to free an unallocated snap id');
});

test('large snapshot id release batches retain every id through repeated reuse', function () {
    $pool = new SnapIdPool;
    $ids  = [];
    for ($i = 0; $i < 8192; $i++) {
        $ids[] = $pool->allocId();
    }
    $order = [...array_reverse(array_slice($ids, 0, 4096)), ...array_slice($ids, 4096)];
    for ($round = 0; $round < 3; $round++) {
        foreach ($order as $id) {
            $pool->freeId($id);
        }
        $reused = [];
        for ($i = 0; $i < count($order); $i++) {
            $reused[] = $pool->allocId();
        }
        expect($reused)->toBe(array_reverse($order));
    }
    expect($pool->allocId())->toBe(8192);
});
