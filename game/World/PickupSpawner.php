<?php

namespace TeeFrame\Game\World;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\AbstractCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkMessages;

class PickupSpawner
{
    /**
     * Scan the game layer for pickup entities and spawn them in the world.
     *
     * @param  array<int, int>  $respawnTimes  Entity type => respawn ticks (-1 = never respawn)
     * @param  array<int, int>  $spawnDelays   Entity type => initial spawn delay in ticks (0 = immediately available)
     */
    public static function spawn(AbstractWorld $world, GameLayer $gameLayer, array $respawnTimes = [], array $spawnDelays = []): void
    {
        foreach ($gameLayer->getEntityPositions() as $entity) {
            $pos    = new Vector2($entity['x'], $entity['y']);
            $pickup = self::createPickup($entity['type'], $pos, $respawnTimes, $spawnDelays);

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
     */
    private static function createPickup(int $entityType, Vector2 $pos, array $respawnTimes, array $spawnDelays): ?PickupEntity
    {
        $respawn    = $respawnTimes[$entityType] ?? -1;
        $spawnDelay = $spawnDelays[$entityType] ?? 0;

        return match ($entityType) {
            GameLayer::ENTITY_ARMOR_1       => new PickupEntity($pos, NetworkMessages::POWERUP_ARMOR, respawnTime: $respawn, spawnDelay: $spawnDelay),
            GameLayer::ENTITY_HEALTH_1      => new PickupEntity($pos, NetworkMessages::POWERUP_HEALTH, respawnTime: $respawn, spawnDelay: $spawnDelay),
            GameLayer::ENTITY_WEAPON_SHOTGUN => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_SHOTGUN, respawnTime: $respawn, spawnDelay: $spawnDelay),
            GameLayer::ENTITY_WEAPON_GRENADE => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_GRENADE, respawnTime: $respawn, spawnDelay: $spawnDelay),
            GameLayer::ENTITY_WEAPON_RIFLE  => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_RIFLE, respawnTime: $respawn, spawnDelay: $spawnDelay),
            GameLayer::ENTITY_POWERUP_NINJA => new PickupEntity($pos, NetworkMessages::POWERUP_NINJA, respawnTime: $respawn, spawnDelay: $spawnDelay),
            default                         => null,
        };
    }
}