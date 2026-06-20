<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class ImageItem extends AbstractMapItem
{
    public function __construct(
        public int $version,
        public int $width,
        public int $height,
        public bool $external,
        public int $imageName,
        public int $imageData,
        public int $format = 0,
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        $version    = $reader->readInt();
        $width      = $reader->readInt();
        $height     = $reader->readInt();
        $external   = $reader->readInt() !== 0;
        $imageName  = $reader->readInt();
        $imageData  = $reader->readInt();
        $format     = 0;

        // Version 2 includes format (0=RGB, 1=RGBA)
        if ($version >= 2) {
            $format = $reader->readInt();
        }

        return new static($version, $width, $height, $external, $imageName, $imageData, $format);
    }
}