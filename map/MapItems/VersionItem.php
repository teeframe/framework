<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class VersionItem extends AbstractMapItem
{
    public function __construct(
        public int $version,
    ) {}

    public static function make(int $id, int $size, string $data): self
    {
        $reader = new MapBufferReader($data);

        return new self(
            $reader->readInt(),
        );
    }
}
