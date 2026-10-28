<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class EnvelopeItem extends AbstractMapItem
{
    public function __construct(
        public int $version,
        public int $channels,
        public int $startPoint,
        public int $numPoints,
        public string $name = '',
        public bool $synchronized = false,
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        $version    = $reader->readInt();
        $channels   = $reader->readInt();
        $startPoint = $reader->readInt();
        $numPoints  = $reader->readInt();

        // Older version 1 envelopes can omit the name field.
        $name = $version === 1 && $reader->remaining() === 0
            ? ''
            : GroupItem::readI32String($reader, 8);

        $synchronized = true;
        if ($version >= 2) {
            $synchronized = $reader->readInt() !== 0;
        }

        return new static($version, $channels, $startPoint, $numPoints, $name, $synchronized);
    }
}
