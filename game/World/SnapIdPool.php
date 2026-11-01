<?php

namespace TeeFrame\Game\World;

class SnapIdPool
{
    /**
     * @var array<int, int> Released IDs keyed by ID, in release order.
     */
    protected array $releasedIds = [];

    public function __construct(protected int $lastAllocatedId = -1, protected int $maximumIdNumber = 16 * 1024) {}

    public function allocId(): int
    {
        if (count($this->releasedIds) > 0) {
            return array_pop($this->releasedIds);
        }

        $nextId = $this->lastAllocatedId + 1;

        if ($nextId > $this->maximumIdNumber) {
            throw new \RuntimeException('No more snap ids available');
        }

        $this->lastAllocatedId = $nextId;

        return $nextId;
    }

    public function freeId(int $id): void
    {
        if (isset($this->releasedIds[$id]) || $id > $this->lastAllocatedId) {
            throw new \RuntimeException('Trying to free an unallocated snap id');
        }

        $this->releasedIds[$id] = $id;
    }
}
