<?php

namespace TeeFrame\Network;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

class SnapshotEncoder
{
    /**
     * @param  AbstractSnapItem[]  $items
     * @return array<int, AbstractSnapItem>
     */
    public function indexItems(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->getKey()] = $item;
        }

        return $indexed;
    }

    /**
     * @param  array<int, AbstractSnapItem>  $items
     * @param  array<int, AbstractSnapItem>  $deltaItems
     * @return list<AbstractChunk>
     */
    public function encode(int $currentTick, int $deltaTick, array $items, array $deltaItems = []): array
    {
        [$changes, $removedCount, $updatedCount] = $this->calculateDelta($items, $deltaItems);
        if ($changes === []) {
            return [new SnapEmptyChunk($currentTick, $deltaTick)];
        }

        $payload = (new RawPayload)
            ->addInt($removedCount)
            ->addInt($updatedCount)
            ->addInt(0)
            ->addBytes($changes)
            ->encode();
        $size   = count($payload);
        $crc    = $this->calculateCrc($items);
        $slices = (int) ceil($size / NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);

        if ($slices > NetworkParams::MAXIMUM_SNAP_SLICES) {
            throw new \RuntimeException('Snap slices limit reached');
        }

        if ($slices === 1) {
            return [new SnapSingleChunk($currentTick, $deltaTick, $crc, $size, $payload)];
        }

        $chunks = [];
        for ($i = 0; $i < $slices; $i++) {
            $slice    = array_slice($payload, $i * NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE, NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);
            $chunks[] = new SnapSliceChunk(
                currentTick: $currentTick,
                deltaTick: $deltaTick,
                totalNumber: $slices,
                currentNumber: $i,
                crc: $crc,
                size: count($slice),
                snapPayload: $slice,
            );
        }

        return $chunks;
    }

    /**
     * @param  array<int, AbstractSnapItem>  $items
     * @param  array<int, AbstractSnapItem>  $deltaItems
     * @return array{int[], int, int} Payload, removed count, updated count.
     */
    public function calculateDelta(array $items, array $deltaItems = []): array
    {
        $payload      = [];
        $removedCount = 0;
        $updatedCount = 0;

        foreach ($deltaItems as $key => $item) {
            if (! isset($items[$key])) {
                $removedCount++;
                $payload = [...$payload, ...NetworkBase::packInt($item->getKey())];
            }
        }

        foreach ($items as $key => $item) {
            $previous = $deltaItems[$key] ?? null;
            if ($previous !== null && $item->getInts() === $previous->getInts()) {
                continue;
            }

            $updatedCount++;
            $payload = [...$payload, ...($previous === null ? $item->encode() : $this->diffItem($previous, $item))];
        }

        return [$payload, $removedCount, $updatedCount];
    }

    /** @param array<int, AbstractSnapItem> $items */
    public function calculateCrc(array $items): int
    {
        $crc = 0;
        foreach ($items as $item) {
            foreach ($item->getInts() as $int) {
                $crc += $int;
            }
        }

        return NetworkBase::toInt32($crc);
    }

    /** @return int[] */
    protected function diffItem(AbstractSnapItem $previous, AbstractSnapItem $item): array
    {
        $previousInts = $previous->getInts();
        $payload      = (new RawPayload)->addInt($item->getItemId())->addInt($item->getId());
        foreach ($item->getInts() as $i => $value) {
            $payload->addInt($value - $previousInts[$i]);
        }

        return $payload->encode();
    }
}
