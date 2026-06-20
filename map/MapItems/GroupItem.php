<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class GroupItem extends AbstractMapItem
{
    public function __construct(
        public int $version,
        public int $offsetX,
        public int $offsetY,
        public int $parallaxX,
        public int $parallaxY,
        public int $startLayer,
        public int $numLayers,
        public bool $useClipping = false,
        public int $clipX = 0,
        public int $clipY = 0,
        public int $clipW = 0,
        public int $clipH = 0,
        public string $name = '',
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        $version    = $reader->readInt();
        $offsetX    = $reader->readInt();
        $offsetY    = $reader->readInt();
        $parallaxX  = $reader->readInt();
        $parallaxY  = $reader->readInt();
        $startLayer = $reader->readInt();
        $numLayers  = $reader->readInt();

        $useClipping = false;
        $clipX = $clipY = $clipW = $clipH = 0;

        if ($version >= 2) {
            $useClipping = $reader->readInt() !== 0;
            $clipX       = $reader->readInt();
            $clipY       = $reader->readInt();
            $clipW       = $reader->readInt();
            $clipH       = $reader->readInt();
        }

        $name = '';
        if ($version >= 3) {
            $name = self::readI32String($reader, 3);
        }

        return new static($version, $offsetX, $offsetY, $parallaxX, $parallaxY, $startLayer, $numLayers, $useClipping, $clipX, $clipY, $clipW, $clipH, $name);
    }

    public static function readI32String(MapBufferReader $reader, int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= pack('V', $reader->readInt());
        }

        // Remove trailing null byte
        $nullPos = strpos($bytes, "\0");
        if ($nullPos !== false) {
            $bytes = substr($bytes, 0, $nullPos);
        }

        // Un-apply the +128 encoding
        $result = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $result .= chr((ord($bytes[$i]) - 128) & 0xFF);
        }

        return $result;
    }
}