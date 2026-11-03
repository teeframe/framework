<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class InfoItem extends AbstractMapItem
{
    public function __construct(
        public int $itemVersion,
        public int $author,
        public int $version,
        public int $credits,
        public int $license,
    ) {}

    public static function make(int $id, int $size, string $data): self
    {
        $reader = new MapBufferReader($data);

        return new self(
            $reader->readInt(),
            $reader->readInt(),
            $reader->readInt(),
            $reader->readInt(),
            $reader->readInt()
        );
    }
}
