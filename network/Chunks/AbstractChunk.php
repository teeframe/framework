<?php

namespace Network\Chunks;

use Network\Enums\Network;

abstract class AbstractChunk
{
    protected int $sequence = -1;

    public function __construct(protected int $flags, protected int $message)
    {
    }

    abstract public function getPayload(): array;

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

    public function getMessage(): int
    {
        return $this->message;
    }

    public function isGameMessage(): bool
    {
        return $this->message > 127;
    }

    public function getSize(): int
    {
        return count($this->getPayload()) + 1; // +1 for the message byte
    }

    public function encode(): array
    {
        $size = $this->getSize();

        $header    = [];
        $header[0] = (($this->flags & 3) << 6) | (($size >> 4) & 0x3F);
        $header[1] = ($size & 0xF);

        if ($this->flags & Network::CHUNKFLAG_VITAL) {
            $header[1] |= ($this->sequence >> 2) & 0xF0;
            $header[2] = $this->sequence         & 0xFF;
        }

        if ($this->message > 127) {
            $message = $this->message - 128;
            $message <<= 1;
        } else {
            $message = $this->message;
            $message <<= 1;
            $message |= 1;
        }

        return [...$header, $message, ...$this->getPayload()];
    }
}