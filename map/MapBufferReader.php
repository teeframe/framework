<?php

namespace TeeFrame\Map;

class MapBufferReader
{
    public function __construct(protected string $buffer)
    {
    }

    public function readBytes(int $length): string
    {
        return $this->extractFromBuffer($length);
    }

    public function readString(): string
    {
        $string = '';

        while (strlen($this->buffer) > 0) {
            $char = $this->extractFromBuffer(1);

            if ($char === "\0") {
                break;
            }

            $string .= $char;
        }

        return $string;
    }

    public function readMagic(): string
    {
        return $this->extractFromBuffer(4);
    }

    public function readInt(): int
    {
        $unpacked = unpack('V', $this->extractFromBuffer(4));

        return $unpacked ? $unpacked[1] : 0;
    }

    protected function extractFromBuffer(int $length): string
    {
        $data         = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }
}
