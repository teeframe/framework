<?php

namespace Network\Connection;

use Network\Chunks\System\SnapChunk;
use Network\Chunks\System\SnapEmptyChunk;
use Network\Chunks\System\SnapSingleChunk;
use Network\SnapItems\AbstractSnapItem;

use Network\NetworkBase;
use Network\NetworkParams;
use Network\SnapSlicesLimitReachedException;

class SnapHandler
{
    const STATE_INIT = 0;
    const STATE_FULL = 1;
    const STATE_RECOVER = 2;

    protected int $state = self::STATE_INIT;

    protected int $lastAckedTick = -1;

    /**
     * @var array<int, ConnectionSnap>
     */
    protected array $sentList = [];

    public function __construct(
        protected Connection $connection
    ) {
    }

    public function setLastAckedTick(int $tick): void
    {
        $this->lastAckedTick = $tick;

        if ($this->state === self::STATE_INIT) {
            $this->state = self::STATE_FULL;
        }

        // Keep only items that are greater or equal to the last acked tick (for delta snap)
        $this->sentList = array_filter($this->sentList, fn(ConnectionSnap $snap): bool => $snap->getTick() >= $tick);
    }

    public function flushSentList(): void
    {
        $this->sentList = [];
    }

    public function resetState(): void
    {
        $this->state = self::STATE_INIT;
    }

    /**
     * @param array<int, AbstractSnapItem> $fullItems
     */
    public function sendSnapItems(int $currentTick, array $fullItems): void
    {
        $deltaTick = $currentTick - $this->lastAckedTick;
        $crc       = $this->calculateCrc($fullItems);

        [$sendablePayload, $removedItemsCount, $updatedItemsCount] = $this->calculateSendablePayload($fullItems);
    
        $payload = [
            ...NetworkBase::packInt($removedItemsCount), 
            ...NetworkBase::packInt($updatedItemsCount), 
            ...NetworkBase::packInt(0),
            ...$sendablePayload
        ];

        if (count($sendablePayload) === 0) {
            $this->connection->chunks()->add(
                new SnapEmptyChunk($currentTick, $deltaTick)
            )->send();
        } else {
            $payloadSize = count($payload);
            $slicesCount = (int) ceil($payloadSize / NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);
    
            if ($slicesCount > NetworkParams::MAXIMUM_SNAP_SLICES) {
                throw new SnapSlicesLimitReachedException();
            }
    
            for ($i=0; $i < $slicesCount; $i++) { 
                if ($slicesCount === 1) {
                    $this->connection->chunks()->add(
                        new SnapSingleChunk($currentTick, $deltaTick, $crc, $payloadSize, $payload)
                    )->send();
    
                    break;
                }
    
                $slicePayload = array_slice($payload, $i * NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE, NetworkParams::MAXIMUM_SNAP_PAYLOAD_SIZE);
                $slicePayloadSize = count($slicePayload);
                
                $this->connection->chunks()->add(
                    new SnapChunk($currentTick, $deltaTick, $crc, $slicesCount, $i + 1, $slicePayloadSize, $slicePayload)
                )->send();
            }    
        }

        $this->sentList[] = new ConnectionSnap($currentTick, $fullItems);
    }

    /**
     * @param array<int, AbstractSnapItem> $items
     * 
     * @return array{array<int, int>, int, int}
     */
    protected function calculateSendablePayload(array $items): array
    {
        $deltaSnap = $this->findDeltaSnap();

        if ($deltaSnap === null) {
            if ($this->state === self::STATE_FULL) {
                $this->state = self::STATE_RECOVER;
            }

            $sendablePayload = $this->collapsePayload(array_map(fn(AbstractSnapItem $item) => $item->encode(), $items));

            return [$sendablePayload, 0, count($items)];
        }

        $deltaItems = $deltaSnap->getSnapItems();

        $removedItems      = [];
        $updatedItems      = [];
        $removedItemsCount = 0;
        $updatedItemsCount = 0;

        // TODO: Refactor this & optimize

        foreach ($deltaItems as $deltaItem) {
            $matchedItem = null;

            foreach ($items as $item) {
                if ($item->getId() === $deltaItem->getId()) {
                    $matchedItem = $item;
                    break;
                }
            }

            if ($matchedItem === null) {
                $removedItemsCount++;

                $removedItems[] = $deltaItem;
            }
        }

        // What happens if ID as "X" was a deleted item,
        // and then a new item with the same ID as "X" is added?

        foreach ($items as $item) {
            $matchedItem = null;

            foreach ($deltaItems as $deltaItem) {
                if ($item->getId() === $deltaItem->getId()) {
                    $matchedItem = $deltaItem;
                    break;
                }
            }

            if ($matchedItem) {
                if ($item->encode() !== $matchedItem->encode()) {
                    $updatedItemsCount++;

                    $updatedItems[] = $item;
                } else {
                    // Item is the same, no need to send it
                }
            } else { // Item is new
                $updatedItemsCount++;

                $updatedItems[] = $item;
            }
        }

        $sendablePayload = [
            ...$this->collapsePayload(array_map(fn(AbstractSnapItem $item) => [$item->getItemId(), $item->getId()], $removedItems)),
            ...$this->collapsePayload(array_map(fn(AbstractSnapItem $item) => $item->encode(), $updatedItems)),
        ];

        return [$sendablePayload, $removedItemsCount, $updatedItemsCount];
    }

    protected function findDeltaSnap(): ?ConnectionSnap
    {
        foreach ($this->sentList as $snap) {
            if ($snap->getTick() === $this->lastAckedTick) {
                return $snap;
            }
        }

        return null;
    }

    /**
     * @param array<int, AbstractSnapItem> $items
     */
    protected function calculateCrc(array $items): int
    {
        $crc = 0;

        foreach ($items as $item) {
            $payload = $item->getPayload();

            for ($i=0; $i < count($payload); $i++) { 
                $crc += $payload[$i];
            }
        }

        return $crc;
    }

    /**
     * @param array<array<int, int>> $payload
     * 
     * @return array<int, int>
     */
    protected function collapsePayload(array $payload): array
    {
        $collapsedPayload = [];

        foreach ($payload as $slicePayload) {
            $collapsedPayload = [...$collapsedPayload, ...$slicePayload];
        }

        return $collapsedPayload;
    }
}