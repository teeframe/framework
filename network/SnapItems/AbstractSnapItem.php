<?php

namespace Network\SnapItems;

abstract class AbstractSnapItem
{
    protected int $id = 0;

    public function __construct(protected int $itemId)
    {
    }

    abstract public function getPayload(): array;

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

    public function encode(): array
    {
        return [$this->itemId, $this->id, ...$this->getPayload()];
    }
}
