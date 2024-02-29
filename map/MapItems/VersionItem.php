<?php

namespace Map\MapItems;

use Map\MapBufferReader;

class VersionItem extends AbstractMapItem
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
            itemVersion: $reader->readInt(), 
            author: $reader->readString(), 
            version: $reader->readString(), 
            credits: $reader->readString(), 
            license: $reader->readString()
        );
    }
}