<?php

namespace TeeFrame\Map\MapLayers;

class GameLayer extends TileLayer
{
    // Entity types (tile indices > 0 and <= ENTITY_OFFSET)
    public const ENTITY_NULL          = 0;
    public const ENTITY_SPAWN         = 192;
    public const ENTITY_SPAWN_RED     = 193;
    public const ENTITY_SPAWN_BLUE    = 194;
    public const ENTITY_FLAGSTAND_RED = 195;
    public const ENTITY_FLAGSTAND_BLUE = 196;
    public const ENTITY_ARMOR_1       = 197;
    public const ENTITY_HEALTH_1      = 198;
    public const ENTITY_WEAPON_SHOTGUN = 199;
    public const ENTITY_WEAPON_GRENADE = 200;
    public const ENTITY_POWERUP_NINJA = 201;
    public const ENTITY_WEAPON_RIFLE  = 202;

    // Collision tile types
    public const TILE_AIR    = 0;
    public const TILE_SOLID  = 1;
    public const TILE_DEATH  = 2;
    public const TILE_NOHOOK = 3;

    // Tile flags
    public const TILEFLAG_VFLIP  = 1;
    public const TILEFLAG_HFLIP  = 2;
    public const TILEFLAG_OPAQUE = 4;
    public const TILEFLAG_ROTATE = 8;

    /**
     * @var array<int, array{x: int, y: int, type: int}>
     */
    protected array $entityPositions = [];

    public function __construct(int $width, int $height, string $rawData, int $version = 3)
    {
        parent::__construct($width, $height, $rawData, $version);

        $this->scanEntities();
    }

    /**
     * Scan all tiles for entity spawn positions.
     */
    protected function scanEntities(): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $index = $this->getTileIndex($x, $y);

                if ($index >= self::ENTITY_SPAWN && $index <= self::ENTITY_WEAPON_RIFLE) {
                    $this->entityPositions[] = [
                        'x'    => $x * 32 + 16,
                        'y'    => $y * 32 + 16,
                        'type' => $index,
                    ];
                }
            }
        }
    }

    /**
     * @return array<int, array{x: int, y: int, type: int}>
     */
    public function getEntityPositions(): array
    {
        return $this->entityPositions;
    }
}
