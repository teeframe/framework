<?php

namespace Game\Core;

class SnapIdPool
{
    /**
     * @var int[]
     */
    protected array $releasedIds = [];

    /**
     * @param int[] $allocatedIds
     */
    public function __construct(protected int $lastAllocatedId = -1, protected int $maximumId = 16 * 1024)
    {
    }

    public function allocId(): int
    {
        if (count($this->releasedIds) > 0) {
            return array_pop($this->releasedIds);
        }

        $nextId = $this->lastAllocatedId + 1;

        if ($nextId < $this->maximumId) {
            $this->lastAllocatedId = $nextId;

            return $nextId;
        }

        throw new \RuntimeException('No more snap ids available');
    }

    public function freeId(int $id): void
    {
        if (in_array($id, $this->releasedIds, true) || $id > $this->lastAllocatedId) {
            throw new \RuntimeException('Trying to free an unallocated snap id');
        }

        $this->releasedIds[] = $id;
    }
}