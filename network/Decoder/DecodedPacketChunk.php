<?php

namespace Network\Decoder;

use Helpers\IntegerHelper;

class DecodedPacketChunk
{
    protected int $pointer = 0;

    public function __construct(public int $flags, public int $sequence, public int $message, protected array $data)
    {
    }

    public function getInt(): int
    {
        if ($this->pointerIsFault()) {
            return 0;
        }

        [$result, $incrementPointer] = IntegerHelper::unpack(array_slice($this->data, $this->pointer));

        $this->pointer += $incrementPointer;

        return $result;
    }

    public function getString(): string
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

    public function getBytes(int $length): array // CUnpacker::GetRaw(int Size) equivalent
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
