<?php

namespace TeeFrame\Map;

use TeeFrame\Map\MapItems\GroupItem;
use TeeFrame\Map\MapItems\LayerItem;
use TeeFrame\Map\MapLayers\GameLayer;

class Map
{
    /**
     * @var array<int, GroupItem>
     */
    protected array $groups = [];

    /**
     * @var array<int, LayerItem>
     */
    protected array $layers = [];

    protected ?GameLayer $gameLayer = null;

    protected ?Collision $collision = null;

    protected ?MapReader $reader = null;

    protected string $name = '';

    protected string $path = '';

    protected int $crc = 0;

    protected int $size = 0;

    /** @var int[] */
    protected array $rawData = [];

    /**
     * @param  string  $mapPath  Path to the .map file (e.g., "maps/dm1.map")
     */
    public function __construct(string $mapPath = '')
    {
        if ($mapPath !== '') {
            $this->load($mapPath);
        }
    }

    public function load(string $mapPath): void
    {
        $reader = new MapReader($mapPath);
        $reader->read();

        [$groups, $layers] = $this->parseItems($reader);
        $gameLayer         = $this->parseGameLayer($reader, $groups, $layers);
        $collision         = $this->createCollision($gameLayer);
        $rawContent        = $reader->getRawContent();
        $crc               = crc32($rawContent);
        $rawData           = array_values(unpack('C*', $rawContent) ?: []);
        $path              = realpath($mapPath) ?: $mapPath;

        // Publish only after every parsing/validation stage succeeds.
        $this->reader    = $reader;
        $this->groups    = $groups;
        $this->layers    = $layers;
        $this->gameLayer = $gameLayer;
        $this->collision = $collision;
        // Teeworlds packs the CRC as a signed 32-bit integer.
        $this->crc     = ($crc & 0x80000000) ? $crc - 0x100000000 : $crc;
        $this->size    = strlen($rawContent);
        $this->rawData = $rawData;
        $this->name    = basename($mapPath, '.map');
        $this->path    = $path;
    }

    /** @return array{array<int, GroupItem>, array<int, LayerItem>} */
    protected function parseItems(MapReader $reader): array
    {
        $groups    = [];
        $layers    = [];
        $groupsRaw = $reader->getItemsByType(4); // MAPITEMTYPE_GROUP
        $layersRaw = $reader->getItemsByType(5); // MAPITEMTYPE_LAYER

        foreach ($groupsRaw as $raw) {
            $groups[] = GroupItem::make($raw['id'], $raw['size'], $raw['data']);
        }

        foreach ($layersRaw as $raw) {
            $layers[] = LayerItem::make($raw['id'], $raw['size'], $raw['data']);
        }

        $usedLayers = [];
        foreach ($groups as $group) {
            if ($group->startLayer < 0 || $group->numLayers < 0
                || $group->startLayer + $group->numLayers > count($layers)
            ) {
                throw new \RuntimeException('Invalid group layer range');
            }
            for ($i = $group->startLayer; $i < $group->startLayer + $group->numLayers; $i++) {
                if (isset($usedLayers[$i])) {
                    throw new \RuntimeException('Overlapping group layer ranges');
                }
                $usedLayers[$i] = true;
            }
        }

        return [$groups, $layers];
    }

    /**
     * @param  array<int, GroupItem>  $groups
     * @param  array<int, LayerItem>  $layers
     */
    protected function parseGameLayer(MapReader $reader, array $groups, array $layers): ?GameLayer
    {
        foreach ($groups as $group) {
            for ($l = 0; $l < $group->numLayers; $l++) {
                $layerIndex = $group->startLayer + $l;
                $layer      = $layers[$layerIndex];

                if (
                    $layer->type === LayerItem::LAYERTYPE_TILES
                    && ($layer->tileFlags & LayerItem::TILESLAYERFLAG_GAME)
                ) {
                    $rawData = $reader->getDataBlock($layer->data);
                    if ($rawData === null) {
                        throw new \RuntimeException('Game layer references a missing data block');
                    }

                    return new GameLayer(
                        $layer->width,
                        $layer->height,
                        $rawData,
                        $layer->version,
                    );
                }
            }
        }

        return null;
    }

    protected function createCollision(?GameLayer $gameLayer): ?Collision
    {
        if ($gameLayer === null) {
            return null;
        }

        $collision = new Collision;
        $collision->init($gameLayer);

        return $collision;
    }

    public function getGameLayer(): ?GameLayer
    {
        return $this->gameLayer;
    }

    public function getCollision(): ?Collision
    {
        return $this->collision;
    }

    /**
     * @return array<int, GroupItem>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<int, LayerItem>
     */
    public function getLayers(): array
    {
        return $this->layers;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCrc(): int
    {
        return $this->crc;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @return int[]
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }
}
