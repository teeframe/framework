<?php

namespace Network\Encoder;

use Network\IntegerHelper;

class PackageChunkEncoder
{
    protected int $sequence = 0;

    public function __construct(protected int $flags, protected int $message, protected array $payload = [])
    {
    }

    public static function make(int $flags, int $message): static
    {
        return new static($flags, $message);
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function addInt(int $value): static
    {
        $this->payload = [...$this->payload, ...IntegerHelper::pack($value)];

        return $this;
    }

    public function addString(string $value): static
    {
        $this->payload = [...$this->payload, ...unpack('C*', $value), 0]; // Alternative?: array_map('ord', str_split($value))

        return $this;
    }

    public function addBytes(array $value): static
    {
        $this->payload = [...$this->payload, ...$value];

        return $this;
    }

    public function encode(): array
    {
        return [$this->flags, $this->sequence, $this->message, ...$this->payload];
    }
}
