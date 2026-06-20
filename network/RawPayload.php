<?php

namespace TeeFrame\Network;

class RawPayload
{
    /**
     * @param int[] $payload
     */
    public function __construct(protected array $payload = [])
    {
    }

    /**
     * @return int[]
     */
    public function encode(): array
    {
        return $this->payload;
    }

    public function reset(): void
    {
        $this->payload = [];
    }

    public function addBool(bool $value): static
    {
        return $this->addInt((int) $value);
    }

    public function addInt(int $value): static
    {
        $this->payload = [...$this->payload, ...NetworkBase::packInt($value)];

        return $this;
    }

    public function addString(string $value): static
    {
        $this->payload = [...$this->payload, ...NetworkBase::unpackBuffer($value), 0];

        return $this;
    }

    /**
     * @param int[] $value
     */
    public function addBytes(array $value): static
    {
        $this->payload = [...$this->payload, ...$value];

        return $this;
    }

    public function extractBool(): bool
    {
        return (bool) $this->extractInt();
    }

    public function extractInt(bool $throw = false): int
    {
        if (count($this->payload) < 1) {
            if ($throw) {
                throw new \RuntimeException('Not enough data to extract int');
            }

            return 0;
        }

        [$result, $bytesAmount] = NetworkBase::unpackInt($this->payload);

        $this->payload = array_slice($this->payload, $bytesAmount);

        return $result;
    }

    public function extractString(): string
    {
        $string = '';

        while (count($this->payload) > 0) {
            $char = array_shift($this->payload);

            if ($char === 0) {
                break;
            }

            $string .= chr($char);
        }

        return $string;
    }

    /**
     * @return int[]
     */
    public function extractBytes(int $length): array
    {
        $safeLength = min($length, count($this->payload));

        $bytes = array_slice($this->payload, 0, $safeLength);

        $this->payload = array_slice($this->payload, $safeLength);

        return $bytes;
    }
}
