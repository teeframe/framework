<?php

namespace Network\Encoder;

use Helpers\IntegerHelper;
use Helpers\IsMakeable;

class PackageChunkEncoder
{
    use IsMakeable;

    public function __construct(protected int $message, protected array $payload = [])
    {
    }

    public function addInt(int $value)
    {
        $this->payload = [...$this->payload, ...IntegerHelper::pack($value)];

        return $this;
    }

    public function addString(string $value)
    {
        $this->payload = [...$this->payload, ...unpack('C*', $value), 0]; // Alternative?: array_map('ord', str_split($value))

        return $this;
    }

    public function addBytes(array $value)
    {
        $this->payload = [...$this->payload, ...$value];

        return $this;
    }

    public function encode(): array
    {
        return [$this->message, ...$this->payload];
    }
}
