<?php

namespace Network\Connection;

use Network\Encoder\Chunks\System\SnapChunk;
use Network\Encoder\Chunks\System\SnapSingleChunk;
use Network\Encoder\SnapItemEncoder;

use Network\NetworkBase;
use Network\Limits;

class SnapHandler
{
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
    }

    public function flushSentList(): void
    {
        $this->sentList = [];
    }

    /**
     * @param array<int, SnapItemEncoder> $items
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
        $slicesCount = (int) ceil($payloadSize / Limits::MAXIMUM_SNAP_PAYLOAD_SIZE);

        if ($slicesCount > Limits::MAXIMUM_SNAP_SLICES) {
            throw new \Exception('Snap payload is too large'); // TODO: Handle this
        }

        for ($i=0; $i < $slicesCount; $i++) { 
            if ($slicesCount === 1) {
                $this->connection->chunks()->add(
                    SnapSingleChunk::make($currentTick, $deltaTick, $crc, $payloadSize, $payload)
                )->send();

                break;
            }

            $slicePayload = array_slice($payload, $i * Limits::MAXIMUM_SNAP_PAYLOAD_SIZE, Limits::MAXIMUM_SNAP_PAYLOAD_SIZE);
            $slicePayloadSize = count($slicePayload);
            
            $this->connection->chunks()->add(
                SnapChunk::make($currentTick, $deltaTick, $crc, $slicesCount, $i + 1, $slicePayloadSize, $slicePayload)
            )->send();
        }

        $this->sentList[] = new ConnectionSnap($currentTick, $items);
    }

    /**
     * @param array<int, SnapItemEncoder> $items
     * 
     * @return array{int, int}
     */
    protected function calculateRemovedAndUpdatedItems(array $items): array
    {
        $deltaSnap = $this->findDeltaSnap();

        if ($deltaSnap === null) {
            return [0, count($items)];
        }

        $deltaItems = $deltaSnap->getSnapItems();

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
     * @param array<int, SnapItemEncoder> $items
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