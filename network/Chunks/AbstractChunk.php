<?php

namespace Network\Chunks;

use Network\NetworkBase;
use Network\RawPayload;

abstract class AbstractChunk
{
    protected int $sequence = -1;

    public function __construct(protected int $flags, protected int $message, protected bool $isSystem = false)
    {
    }

    abstract public function getPayload(): RawPayload;

    abstract public static function make(RawPayload $payload): static;

    public function addResendFlag(): static
    {
        $this->flags |= NetworkBase::CHUNK_FLAG_RESEND;

        return $this;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function encode(): array
    {
        $encodedPayload = $this->getPayload()->encode();
        $size           = count($encodedPayload) + 1; // +1 for the message byte

        $header    = [];
        $header[0] = (($this->flags & 3) << 6) | (($size >> 4) & 0x3F);
        $header[1] = ($size & 0xF);

        if ($this->flags & NetworkBase::CHUNK_FLAG_VITAL) {
            $header[1] |= ($this->sequence >> 2) & 0xF0;
            $header[2] = $this->sequence         & 0xFF;
        }

        $message = $this->message;
        $message <<= 1;

        if ($this->isSystem) {
            $message |= 1;
        }

        return [...$header, $message, ...$encodedPayload];
    }
}
