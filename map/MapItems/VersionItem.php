<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class VersionItem extends AbstractMapItem
{
    public function __construct(
        public int $version,
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        return new static(
            $reader->readInt(),
        );
    }
}