<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\RawPayload;

abstract class AbstractSnapItem
{
    protected int $id = -1;

    /**
     * @param  array<int, int>  $integers
     */
    public function __construct(protected int $itemId)
    {
    }

    /**
     * @return int[]
     */
    abstract public function getInts(): array;

    public function getKey(): int
    {
        return ($this->itemId << 16) | $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return array<int, int>
     */
    public function encode(): array
    {
        if ($this->id === -1) {
            throw new \RuntimeException('Trying to encode a snap item without an id');
        }

        return [$this->itemId, $this->id, ...$this->getPayload()->encode()];
    }

    protected function getPayload(): RawPayload
    {
        $rawPayload = new RawPayload;

        foreach ($this->getInts() as $int) {
            $rawPayload->addInt($int);
        }

        return $rawPayload;
    }
}
