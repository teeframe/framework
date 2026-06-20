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

        // Name: 8 i32s
        $name = GroupItem::readI32String($reader, 8);

        $synchronized = false;
        if ($version >= 2) {
            $synchronized = $reader->readInt() !== 0;
        }

        return new static($version, $channels, $startPoint, $numPoints, $name, $synchronized);
    }
}