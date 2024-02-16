<?php

namespace Network\Encoder;

use Network\IntegerHelper;

class PackageChunkSnapEncoder
{
    public function __construct(protected int $itemId, protected int $id = 0, protected array $payload = [])
    {
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function addInt(int $value): static
    {
        $this->payload = [...$this->payload, ...IntegerHelper::pack($value)];

        return $this;
    }

    public function encode(): array
    {
        return [$this->itemId, $this->id, ...$this->payload];
    }
}
