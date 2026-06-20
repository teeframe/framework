<?php

namespace TeeFrame\Map;

use TeeFrame\Map\MapItems\AbstractMapItem;
use TeeFrame\Map\MapItems\GroupItem;
use TeeFrame\Map\MapItems\InfoItem;
use TeeFrame\Map\MapItems\LayerItem;
use TeeFrame\Map\MapLayers\GameLayer;

class Map
{
    /**
     * @var array<int, GroupItem>
     */
    private array $groups = [];

    /**
     * @var array<int, LayerItem>
     */
    private array $layers = [];

    private ?GameLayer $gameLayer = null;

    private ?Collision $collision = null;

    private ?MapReader $reader = null;

    private string $name = '';

    private int $crc = 0;

    private int $size = 0;

    /** @var int[] */
    private array $rawData = [];

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
        $this->reader = new MapReader($mapPath);
        $this->reader->read();

        $this->size = filesize($mapPath) ?: 0;
        $rawContent = file_get_contents($mapPath);

        if ($rawContent !== false) {
            $crc = crc32($rawContent);
            // Convert to signed 32-bit for Teeworlds protocol compatibility.
            // PHP's crc32() returns unsigned on 64-bit, but Teeworlds
            // packInt expects signed 32-bit integers.
            $this->crc = ($crc & 0x80000000) ? $crc - 0x100000000 : $crc;
            $this->rawData = array_values(unpack('C*', $rawContent) ?: []);
        }

        $this->name = basename($mapPath, '.map');

        $this->parseItems();
    }

    protected function parseItems(): void
    {
        if ($this->reader === null) {
            return;
        }

        $groupsRaw = $this->reader->getItemsByType(4); // MAPITEMTYPE_GROUP
        $layersRaw = $this->reader->getItemsByType(5); // MAPITEMTYPE_LAYER

        foreach ($groupsRaw as $raw) {
            $this->groups[] = GroupItem::make($raw['id'], $raw['size'], $raw['data']);
        }

        foreach ($layersRaw as $raw) {
            $this->layers[] = LayerItem::make($raw['id'], $raw['size'], $raw['data']);
        }

        $this->initGameLayer();
        $this->initCollision();
    }

    protected function initGameLayer(): void
    {
        if ($this->reader === null) {
            return;
        }

        foreach ($this->groups as $group) {
            for ($l = 0; $l < $group->numLayers; $l++) {
                $layerIndex = $group->startLayer + $l;
                if (! isset($this->layers[$layerIndex])) {
                    continue;
                }

                $layer = $this->layers[$layerIndex];

                if (
                    $layer->type === LayerItem::LAYERTYPE_TILES
                    && ($layer->tileFlags & LayerItem::TILESLAYERFLAG_GAME)
                ) {
                    $rawData = $this->reader->getDataBlock($layer->data) ?? '';

                    $this->gameLayer = new GameLayer(
                        $layer->width,
                        $layer->height,
                        $rawData,
                        $layer->version,
                    );

                    return;
                }
            }
        }
    }

    protected function initCollision(): void
    {
        if ($this->gameLayer === null) {
            return;
        }

        $this->collision = new Collision;
        $this->collision->init($this->gameLayer);
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