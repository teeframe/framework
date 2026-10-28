<?php

namespace TeeFrame\Map;

class MapBufferReader
{
    public function __construct(protected string $buffer) {}

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
                return $string;
            }

            $string .= $char;
        }

        throw new \RuntimeException('Unterminated map string');
    }

    public function readMagic(): string
    {
        return $this->extractFromBuffer(4);
    }

    public function readInt(): int
    {
        $unpacked = unpack('V', $this->extractFromBuffer(4));

        $value = $unpacked ? $unpacked[1] : 0;

        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    /**
     * @return int[]
     */
    public function readInts(int $count): array
    {
        if ($count < 0 || $count > intdiv($this->remaining(), 4)) {
            throw new \RuntimeException('Invalid map integer count or truncated buffer');
        }

        $ints = [];
        for ($i = 0; $i < $count; $i++) {
            $ints[] = $this->readInt();
        }

        return $ints;
    }

    public function remaining(): int
    {
        return strlen($this->buffer);
    }

    protected function extractFromBuffer(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new \RuntimeException('Invalid map read length or truncated buffer');
        }

        $data         = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }
}
