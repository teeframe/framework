<?php

namespace Network\Encoder;

use Helpers\IntegerHelper;

class PackageChunkEncoder
{
    public function __construct(protected int $message, protected array $payload = [])
    {
    }

    public static function make(int $message): static
    {
        return new static($message);
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
        return [$this->message, ...$this->payload];
    }
}
