<?php

namespace TeeFrame\Map\MapLayers;

class TileLayer
{
    /**
     * @var array<int, array{index: int, flags: int, skip: int}>
     */
    protected array $tiles = [];

    public function __construct(
        public int $width,
        public int $height,
        string $rawData,
        int $version = 3,
    ) {
        $this->parseTiles($rawData, $version);
    }

    /**
     * Parse raw tile data into tile array.
     * For version >= 4, tiles use skip compression.
     */
    protected function parseTiles(string $rawData, int $version): void
    {
        $rawTiles = [];
        $dataLen  = strlen($rawData);

        for ($i = 0; $i < $dataLen; $i += 4) {
            $rawTiles[] = [
                'index' => ord($rawData[$i]),
                'flags' => ord($rawData[$i + 1]),
                'skip'  => ord($rawData[$i + 2]),
            ];
        }

        if ($version >= 4) {
            // Expand skip-compressed tiles
            $expanded = [];
            foreach ($rawTiles as $tile) {
                $skip = $tile['skip'];
                $tile['skip'] = 0;
                $expanded[]   = $tile;
                for ($s = 0; $s < $skip; $s++) {
                    $expanded[] = $tile;
                }
            }
            $this->tiles = $expanded;
        } else {
            $this->tiles = $rawTiles;
        }
    }

    /**
     * @return array{index: int, flags: int, skip: int}|null
     */
    public function getTile(int $x, int $y): ?array
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            return null;
        }

        $index = $y * $this->width + $x;

        return $this->tiles[$index] ?? null;
    }

    public function getTileIndex(int $x, int $y): int
    {
        $tile = $this->getTile($x, $y);

        return $tile ? $tile['index'] : 0;
    }

    /**
     * @return array<int, array{index: int, flags: int, skip: int}>
     */
    public function getTiles(): array
    {
        return $this->tiles;
    }
}