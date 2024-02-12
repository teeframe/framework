<?php

namespace Network\Decoder;

use Network\IntegerHelper;

class DecodedPacketChunk
{
    protected int $pointer = 0;

    public function __construct(protected int $flags, protected int $sequence, protected int $message, protected array $data)
    {
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getMessage(): int
    {
        return $this->message;
    }

    public function extractInt(): int
    {
        if ($this->pointerIsFault()) {
            return 0;
        }

        [$result, $incrementPointer] = IntegerHelper::unpack(array_slice($this->data, $this->pointer));

        $this->pointer += $incrementPointer;

        return $result;
    }

    public function extractString(): string
    {
        if ($this->pointerIsFault()) {
            return '';
        }

        $string = '';

        while (! $this->pointerIsFault()) {
            $char = $this->data[$this->pointer];

            if ($char === 0) {
                break;
            }

            $string .= chr($char);
            $this->pointer++;
        }

        return $string;
    }

    public function extractBytes(int $length): array // CUnpacker::GetRaw(int Size) equivalent
    {
        if ($this->pointerIsFault()) {
            return [];
        }

        $bytes = [];

        for ($i = 0; $i < $length; $i++) {
            if ($this->pointerIsFault()) {
                break;
            }

            $bytes[] = $this->data[$this->pointer];
            $this->pointer++;
        }

        return $bytes;
    }

    protected function pointerIsFault(): bool
    {
        return $this->pointer >= count($this->data);
    }
}
