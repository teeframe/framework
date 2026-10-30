<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapshotEncoder;

class SnapHandler
{
    const STATE_INIT    = 0;
    const STATE_FULL    = 1;
    const STATE_RECOVER = 2;

    protected int $state;

    protected int $lastAckedTick;

    protected int $latency;

    protected int $minimumLatency;

    protected float $latencyWindowStart;

    protected ?ConnectionSnap $deltaSnap;

    protected SnapshotEncoder $encoder;

    /**
     * @var ConnectionSnap[]
     */
    protected array $sentList = [];

    public function __construct(
        protected AbstractConnection $connection,
        protected TickHandler $tickHandler,
    ) {
        $this->encoder = new SnapshotEncoder;
        $this->reset();
    }

    public function reset(): void
    {
        $this->state              = self::STATE_INIT;
        $this->lastAckedTick      = -1;
        $this->latency            = 0;
        $this->minimumLatency     = 1000;
        $this->latencyWindowStart = $this->tickHandler->getTime() / 1_000_000_000;
        $this->deltaSnap          = null;

        $this->flushSentList();
    }

    public function flushSentList(): void
    {
        $this->sentList = [];
    }

    /** The minimum observed RTT published once per second. */
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

        // Select new delta snap and compute latency using monotonic send/ACK times.
        // Ported from Teeworlds 0.6: (time_get() - TagTime) * 1000 / time_freq()
        foreach ($this->sentList as $snap) {
            if ($snap->getTick() === $this->lastAckedTick) {
                $this->deltaSnap      = $snap;
                $now                  = $this->tickHandler->getTime() / 1_000_000_000;
                $sample               = (int) (($now - $snap->getSendTime()) * 1000);
                $this->minimumLatency = min($this->minimumLatency, $sample);

                // Publish the one-second minimum here, alongside the RTT measurement.
                if ($now - $this->latencyWindowStart >= 1.0) {
                    $this->latency            = $this->minimumLatency;
                    $this->minimumLatency     = 1000;
                    $this->latencyWindowStart = $now;
                }
            }
        }

        // Keep only items that are greater or equal to the last acked tick (for delta snap)
        $this->sentList = array_filter($this->sentList, fn (ConnectionSnap $snap): bool => $snap->getTick() >= $tick);
    }

    /** @param AbstractSnapItem[] $rawItems */
    public function sendItems(int $currentTick, array $rawItems): void
    {
        $indexedItems = $this->encoder->indexItems($rawItems);
        if ($this->deltaSnap === null && $this->state === self::STATE_FULL) {
            $this->state = self::STATE_RECOVER;
        }

        $chunks = $this->encoder->encode(
            currentTick: $currentTick,
            deltaTick: $currentTick - $this->lastAckedTick,
            items: $indexedItems,
            deltaItems: $this->deltaSnap?->getSnapItems() ?? [],
        );
        foreach ($chunks as $chunk) {
            $this->connection->chunks()->add($chunk)->send();
        }

        $this->sentList[] = new ConnectionSnap($currentTick, $indexedItems, $this->tickHandler->getTime() / 1_000_000_000);
    }
}
