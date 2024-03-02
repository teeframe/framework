<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class InfoItem extends AbstractMapItem
{
    public function __construct(
        public int $itemVersion,
        public string $author,
        public string $version,
        public string $credits,
        public string $license,
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        return new static(
            $reader->readInt(), 
            $reader->readString(), 
            $reader->readString(), 
            $reader->readString(), 
            $reader->readString()
        );
    }
}