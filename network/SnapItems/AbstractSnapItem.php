<?php

namespace Network\SnapItems;

use Network\RawPayload;

abstract class AbstractSnapItem
{
    protected int $id = 0;

    public function __construct(protected int $itemId)
    {
    }

    abstract public function getPayload(): RawPayload;

    public function getPayloadInts(): array
    {
        $payload = clone $this->getPayload();

        $integers = [];
        while (true) {
            $rawInt = $payload->extractInt();

            if ($rawInt === -1) {
                break;
            }

            $integers[] = $rawInt;
        }

        return $integers;
    }

    public function resetPayload(): void
    {
        $this->getPayload()->reset();
    }

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

    public function encode(): array
    {
        return [$this->itemId, $this->id, ...$this->getPayload()->encode()];
    }
}
