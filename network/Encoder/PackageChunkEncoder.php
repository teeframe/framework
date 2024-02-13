<?php

namespace Network\Encoder;

use Network\Enums\Network;
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
        $size = count($this->payload) + 1; // +1 for the message byte

        $header    = [];
        $header[0] = (($this->flags & 3) << 6) | (($size >> 4) & 0x3F);
        $header[1] = ($size & 0xF);

        if ($this->flags & Network::CHUNKFLAG_VITAL) {
            $header[1] |= ($this->sequence >> 2) & 0xF0;
            $header[2] = $this->sequence         & 0xFF;
        }

        $message = $this->message;
        $message <<= 1;
        $message |= 1;

        return [...$header, $message, ...$this->payload];
    }
}
