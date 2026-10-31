<?php

namespace TeeFrame\Network\Connection;

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapshotEncoder;

class SnapHandler
{
    const STATE_INIT              = 0;
    const STATE_FULL              = 1;
    const STATE_RECOVER           = 2;
    protected const HISTORY_TICKS = 3 * NetworkParams::TICKS_PER_SECOND;

    protected int $state;

    protected int $lastAckedTick;

    protected int $latency;

    protected int $minimumLatency;

    protected float $latencyWindowStart;

    protected SnapshotEncoder $encoder;

    /**
     * @var array<int, ConnectionSnap> Snapshots keyed by their tick.
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

        $this->flushSentList();
    }

    public function flushSentList(): void
    {
        $this->sentList = [];
    }

    public function shouldSend(int $currentTick): bool
    {
        return match ($this->state) {
            self::STATE_INIT    => $currentTick % intdiv(NetworkParams::TICKS_PER_SECOND, 5) === 0,
            self::STATE_RECOVER => $currentTick % NetworkParams::TICKS_PER_SECOND === 0,
            default             => true,
        };
    }

    /** The minimum observed RTT published once per second. */
    public function getLatency(): int
    {
        return $this->latency;
    }

    public function setLastAckedTick(int $tick): void
    {
        $this->lastAckedTick = $tick;
        if ($tick > 0) {
            $this->state = self::STATE_FULL;
        }

        $snap = $this->sentList[$tick] ?? null;
        if ($snap === null) {
            return;
        }

        // Teeworlds 0.6: (time_get() - TagTime) * 1000 / time_freq().
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

    /** @param AbstractSnapItem[] $rawItems */
    public function sendItems(int $currentTick, array $rawItems): void
    {
        $indexedItems = $this->encoder->indexItems($rawItems);

        // Bound history by age, not ACK progress, including the selected base.
        $oldestTick     = $currentTick - self::HISTORY_TICKS;
        $this->sentList = array_filter($this->sentList, fn (ConnectionSnap $snap): bool => $snap->getTick() >= $oldestTick);
        $deltaSnap      = $this->sentList[$this->lastAckedTick] ?? null;
        if ($deltaSnap === null && $this->state === self::STATE_FULL) {
            $this->state = self::STATE_RECOVER;
        }

        $chunks = $this->encoder->encode(
            currentTick: $currentTick,
            deltaTick: $currentTick - ($deltaSnap?->getTick() ?? -1),
            items: $indexedItems,
            deltaItems: $deltaSnap?->getSnapItems() ?? [],
        );
        foreach ($chunks as $chunk) {
            $this->connection->chunks()->add($chunk)->send();
        }

        $this->sentList[$currentTick] = new ConnectionSnap($currentTick, $indexedItems, $this->tickHandler->getTime() / 1_000_000_000);
    }
}
