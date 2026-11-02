<?php

namespace TeeFrame\Map;

class MapBufferReader
{
    protected int $offset = 0;

    public function __construct(protected string $buffer) {}

    public function readBytes(int $length): string
    {
        return $this->extractFromBuffer($length);
    }

    public function readString(): string
    {
        $end = strpos($this->buffer, "\0", $this->offset);
        if ($end === false) {
            $this->offset = strlen($this->buffer);

            throw new \UnexpectedValueException('Unterminated map string');
        }

        $string       = substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $string;
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
        if ($count < 0) {
            throw new \InvalidArgumentException('Negative map integer count');
        }
        if ($count > intdiv($this->remaining(), 4)) {
            throw new \UnexpectedValueException('Truncated map integer buffer');
        }

        $ints = [];
        for ($i = 0; $i < $count; $i++) {
            $ints[] = $this->readInt();
        }

        return $ints;
    }

    public function remaining(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    protected function extractFromBuffer(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Negative map read length');
        }
        if ($length > $this->remaining()) {
            throw new \UnexpectedValueException('Truncated map buffer');
        }

        $data = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $data;
    }
}
