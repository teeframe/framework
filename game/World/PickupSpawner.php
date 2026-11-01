<?php

namespace TeeFrame\Game\World;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Map\MapLayers\GameLayer;

class PickupSpawner
{
    /**
     * Scan the game layer for pickup entities and spawn them in the world.
     *
     * @param  array<int, int>  $respawnTimes  Entity type => respawn ticks (-1 = never respawn)
     * @param  array<int, int>  $spawnDelays  Entity type => initial spawn delay in ticks (0 = immediately available)
     * @param  array<int, bool>  $alwaysAvailable  Entity type => keep available after collection
     */
    public static function spawn(
        AbstractWorld $world,
        GameLayer $gameLayer,
        array $respawnTimes = [],
        array $spawnDelays = [],
        array $alwaysAvailable = [],
    ): void {
        foreach ($gameLayer->getEntityPositions() as $entity) {
            $pos    = new Vector2($entity['x'], $entity['y']);
            $pickup = self::createPickup($entity['type'], $pos, $world, $respawnTimes, $spawnDelays, $alwaysAvailable);

            if ($pickup !== null) {
                $world->addEntity($pickup);
            }
        }
    }

    /**
     * Map a game layer entity type to a PickupEntity.
     *
     * @param  array<int, int>  $respawnTimes
     * @param  array<int, int>  $spawnDelays
     * @param  array<int, bool>  $alwaysAvailable
     */
    protected static function createPickup(
        int $entityType,
        Vector2 $pos,
        AbstractWorld $world,
        array $respawnTimes,
        array $spawnDelays,
        array $alwaysAvailable,
    ): ?PickupEntity {
        $respawn    = $respawnTimes[$entityType] ?? -1;
        $spawnDelay = $spawnDelays[$entityType] ?? 0;
        $available  = $alwaysAvailable[$entityType] ?? false;

        return match ($entityType) {
            GameLayer::ENTITY_ARMOR_1        => new PickupEntity($world, $pos, GameConstants::POWERUP_ARMOR, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            GameLayer::ENTITY_HEALTH_1       => new PickupEntity($world, $pos, GameConstants::POWERUP_HEALTH, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            GameLayer::ENTITY_WEAPON_SHOTGUN => new PickupEntity($world, $pos, GameConstants::POWERUP_WEAPON, GameConstants::WEAPON_SHOTGUN, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            GameLayer::ENTITY_WEAPON_GRENADE => new PickupEntity($world, $pos, GameConstants::POWERUP_WEAPON, GameConstants::WEAPON_GRENADE, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            GameLayer::ENTITY_WEAPON_RIFLE   => new PickupEntity($world, $pos, GameConstants::POWERUP_WEAPON, GameConstants::WEAPON_RIFLE, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            GameLayer::ENTITY_POWERUP_NINJA  => new PickupEntity($world, $pos, GameConstants::POWERUP_NINJA, respawnTime: $respawn, spawnDelay: $spawnDelay, alwaysAvailable: $available),
            default                          => null,
        };
    }
}
