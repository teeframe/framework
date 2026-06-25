<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Network\Chunks\System\SnapEmptyChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\System\SnapSliceChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\RawPayload;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

class SnapHandler
{
    const STATE_INIT    = 0;
    const STATE_FULL    = 1;
    const STATE_RECOVER = 2;

    protected int $state;

    protected int $lastAckedTick;

    protected int $latency;

    protected ?ConnectionSnap $deltaSnap;

    /**
     * @var ConnectionSnap[]
     */
    protected array $sentList = [];

    public function __construct(
        protected AbstractConnection $connection
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->state         = self::STATE_INIT;
        $this->lastAckedTick = -1;
        $this->latency       = 0;
        $this->deltaSnap     = null;

        $this->flushSentList();
    }

    public function flushSentList(): void
    {
        $this->sentList = [];
    }

    public function getLatency(): int
    {
        return $this->latency;
    }

    public function setLastAckedTick(int $tick): void
    {
        $this->lastAckedTick = $tick;

        if ($this->state !== self::STATE_FULL) {
            $this->state = self::STATE_FULL;
        }

        // Select new delta snap and compute latency from wall-clock send time.
        // Ported from Teeworlds 0.6: (time_get() - TagTime) * 1000 / time_freq()
        foreach ($this->sentList as $snap) {
            if ($snap->getTick() === $this->lastAckedTick) {
                $this->deltaSnap = $snap;
                $this->latency   = (int) round((microtime(true) - $snap->getSendTime()) * 1000);
            }
        }

        // Keep only items that are greater or equal to the last acked tick (for delta snap)
        $this->sentList = array_filter($this->sentList, fn (ConnectionSnap $snap): bool => $snap->getTick() >= $tick);
    }

    /**
     * @param  AbstractSnapItem[]  $rawItems
     */
    public function sendItems(int $currentTick, array $rawItems): void
    {
        $indexedItems = $this->indexItemsList($rawItems);

        $deltaTick = $currentTick - $this->lastAckedTick;
        $crc       = $this->calculateCrc($indexedItems);

        [$sendablePayload, $removedItemsCount, $updatedItemsCount] = $this->calculateSendablePayload($indexedItems);

        $payload = [
            ...NetworkBase::packInt($removedItemsCount),
            ...NetworkBase::packInt($updatedItemsCount),
            ...NetworkBase::packInt(0),
            ...$sendablePayload,
        ];

        if (count($sendablePayload) === 0) {
            $this->connection->chunks()->add(
                new SnapEmptyChunk($currentTick, $deltaTick)
            )->send();
        } else {
            $payloadSize = count($payload);
            $slicesCount = (int) ceil($payloadSize / NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);

            if ($slicesCount > NetworkParams::MAXIMUM_SNAP_SLICES) {
                throw new \RuntimeException('Snap slices limit reached');
            }

            for ($i = 0; $i < $slicesCount; $i++) {
                if ($slicesCount === 1) {
                    $this->connection->chunks()->add(
                        new SnapSingleChunk($currentTick, $deltaTick, $crc, $payloadSize, $payload)
                    )->send();

                    break;
                }

                $slicePayload     = array_slice($payload, $i * NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE, NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);
                $slicePayloadSize = count($slicePayload);

                $this->connection->chunks()->add(
                    new SnapSliceChunk($currentTick, $deltaTick, $crc, $slicesCount, $i + 1, $slicePayloadSize, $slicePayload)
                )->send();
            }
        }

        $this->sentList[] = new ConnectionSnap($currentTick, $indexedItems, microtime(true));
    }

    /**
     * @param  array<int, AbstractSnapItem>  $items
     * @return array{array<int, int>, int, int}
     */
    protected function calculateSendablePayload(array $items): array
    {
        if ($this->deltaSnap === null) {
            if ($this->state === self::STATE_FULL) {
                $this->state = self::STATE_RECOVER;
            }

            $sendablePayload = $this->collapsePayload(array_map(fn (AbstractSnapItem $item) => $item->encode(), $items));

            return [$sendablePayload, 0, count($items)];
        }

        $deltaItems        = $this->deltaSnap->getSnapItems();
        $removedItems      = [];
        $updatedItems      = [];
        $removedItemsCount = 0;
        $updatedItemsCount = 0;

        foreach ($deltaItems as $deltaItemKey => $deltaItem) {
            // Item was in delta snap but not in the current
            if (! isset($items[$deltaItemKey])) {
                $removedItemsCount++;

                $removedItems = [
                    ...$removedItems,
                    ...NetworkBase::packInt($deltaItem->getKey())
                ];
            }
        }

        foreach ($items as $itemKey => $item) {
            $matchedDeltaItem = $deltaItems[$itemKey] ?? null;

            if ($matchedDeltaItem !== null) {
                if ($item->getInts() === $matchedDeltaItem->getInts()) {
                    continue; // Item is the same, no need to send it
                }

                // Item is updated
                $updatedItemsCount++;
                $updatedItems = [...$updatedItems, ...$this->diffItem($matchedDeltaItem, $item)];
            } else {
                // Item is new
                $updatedItemsCount++;
                $updatedItems = [...$updatedItems, ...$item->encode()];
            }
        }

        return [[...$removedItems, ...$updatedItems], $removedItemsCount, $updatedItemsCount];
    }

    /**
     * @return int[]
     */
    protected function diffItem(AbstractSnapItem $deltaItem, AbstractSnapItem $item): array
    {
        $deltaItemInts = $deltaItem->getInts();
        $itemInts      = $item->getInts();

        $diffPayload = new RawPayload;
        foreach ($itemInts as $i => $itemInt) {
            $diffPayload->addInt($itemInt - $deltaItemInts[$i]);
        }

        return [
            ...NetworkBase::packInt($item->getItemId()),
            ...NetworkBase::packInt($item->getId()),
            ...$diffPayload->encode(),
        ];
    }

    /**
     * @param  AbstractSnapItem[]  $items
     */
    protected function calculateCrc(array $items): int
    {
        $crc = 0;

        foreach ($items as $item) {
            $payloadInts = $item->getInts();

            foreach ($payloadInts as $int) {
                $crc += $int;
            }
        }

        return NetworkBase::toInt32($crc);
    }

    /**
     * @param  array<array<int, int>>  $payload
     * @return int[]
     */
    protected function collapsePayload(array $payload): array
    {
        $collapsedPayload = [];

        foreach ($payload as $slicePayload) {
            $collapsedPayload = [...$collapsedPayload, ...$slicePayload];
        }

        return $collapsedPayload;
    }

    /**
     * @param  AbstractSnapItem[]  $items
     * @return array<int, AbstractSnapItem>
     */
    protected function indexItemsList(array $items): array
    {
        $indexedItems = [];

        foreach ($items as $item) {
            $indexedItems[$item->getKey()] = $item;
        }

        return $indexedItems;
    }
}
