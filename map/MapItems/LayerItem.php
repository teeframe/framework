<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class LayerItem extends AbstractMapItem
{
    // Layer types
    public const LAYERTYPE_TILES = 2;
    public const LAYERTYPE_QUADS = 3;

    // Tilemap flags
    public const TILESLAYERFLAG_GAME = 1;

    // Layer flags
    public const LAYERFLAG_DETAIL = 1;

    public function __construct(
        public int $type,
        public int $flags,
        // Tilemap fields
        public int $version = 0,
        public int $width = 0,
        public int $height = 0,
        public int $tileFlags = 0,
        public int $colorR = 255,
        public int $colorG = 255,
        public int $colorB = 255,
        public int $colorA = 255,
        public int $colorEnv = -1,
        public int $colorEnvOffset = 0,
        public int $image = -1,
        public int $data = -1,
        public string $name = '',
        // Quads fields
        public int $numQuads = 0,
        public int $quadData = -1,
        public int $quadImage = -1,
    ) {
        parent::__construct();
    }

    public static function make(int $id, int $size, string $data): static
    {
        $reader = new MapBufferReader($data);

        $reader->readInt(); // _version (unused)
        $type  = $reader->readInt();
        $flags = $reader->readInt();

        if ($type === self::LAYERTYPE_TILES) {
            return self::makeTilemap($reader, $type, $flags);
        }

        if ($type === self::LAYERTYPE_QUADS) {
            return self::makeQuads($reader, $type, $flags);
        }

        return new static($type, $flags);
    }

    protected static function makeTilemap(MapBufferReader $reader, int $type, int $flags): static
    {
        $version        = $reader->readInt();
        $width          = $reader->readInt();
        $height         = $reader->readInt();
        $tileFlags      = $reader->readInt();
        $colorR         = $reader->readInt();
        $colorG         = $reader->readInt();
        $colorB         = $reader->readInt();
        $colorA         = $reader->readInt();
        $colorEnv       = $reader->readInt();
        $colorEnvOffset = $reader->readInt();
        $image          = $reader->readInt();
        $data           = $reader->readInt();

        $name = '';
        if ($version >= 3) {
            $name = GroupItem::readI32String($reader, 3);
        }

        return new static(
            type: $type,
            flags: $flags,
            version: $version,
            width: $width,
            height: $height,
            tileFlags: $tileFlags,
            colorR: $colorR,
            colorG: $colorG,
            colorB: $colorB,
            colorA: $colorA,
            colorEnv: $colorEnv,
            colorEnvOffset: $colorEnvOffset,
            image: $image,
            data: $data,
            name: $name,
        );
    }

    protected static function makeQuads(MapBufferReader $reader, int $type, int $flags): static
    {
        $version   = $reader->readInt();
        $numQuads  = $reader->readInt();
        $quadData  = $reader->readInt();
        $quadImage = $reader->readInt();

        $name = '';
        if ($version >= 2) {
            $name = GroupItem::readI32String($reader, 3);
        }

        return new static(
            type: $type,
            flags: $flags,
            version: $version,
            numQuads: $numQuads,
            quadData: $quadData,
            quadImage: $quadImage,
            name: $name,
        );
    }
}