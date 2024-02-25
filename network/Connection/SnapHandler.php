<?php

namespace Network\Connection;

use Network\Chunks\System\SnapChunk;
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

        // TODO: Add auto remove of sentList items
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
     * @param array<int, AbstractSnapItem> $items
     */
    public function sendSnapItems(int $currentTick, array $items): void
    {
        $deltaTick = $currentTick - $this->lastAckedTick;
        $crc       = $this->calculateCrc($items);

        [$removedItems, $updatedItems] = $this->calculateRemovedAndUpdatedItems($items);

        $payload = [...NetworkBase::packInt($removedItems), ...NetworkBase::packInt($updatedItems), ...NetworkBase::packInt(0)];
        foreach ($items as $item) {
            $payload = [...$payload, ...$item->encode()];
        }

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

        $this->sentList[] = new ConnectionSnap($currentTick, $items);
    }

    /**
     * @param array<int, AbstractSnapItem> $items
     * 
     * @return array{int, int}
     */
    protected function calculateRemovedAndUpdatedItems(array $items): array
    {
        $deltaSnap = $this->findDeltaSnap();

        if ($deltaSnap === null) {
            if ($this->state === self::STATE_FULL) {
                $this->state = self::STATE_RECOVER;
            }

            return [0, count($items)];
        }

        $deltaItems = $deltaSnap->getSnapItems();

        // TODO: Implement filtering of items and return

        $removedItems = 0;
        $updatedItems = 0;

        foreach ($items as $item) {
            $found = false;

            foreach ($deltaItems as $deltaItem) {
                if ($item->getId() === $deltaItem->getId()) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $removedItems++;
            } else {
                $updatedItems++;
            }
        }

        return [$removedItems, $updatedItems];
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
}