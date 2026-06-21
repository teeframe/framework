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
     */
    public static function spawn(AbstractWorld $world, GameLayer $gameLayer, array $respawnTimes = []): void
    {
        foreach ($gameLayer->getEntityPositions() as $entity) {
            $pos    = new Vector2($entity['x'], $entity['y']);
            $pickup = self::createPickup($entity['type'], $pos, $respawnTimes);

            if ($pickup !== null) {
                $world->addEntity($pickup);
            }
        }
    }

    /**
     * Map a game layer entity type to a PickupEntity.
     *
     * @param  array<int, int>  $respawnTimes
     */
    private static function createPickup(int $entityType, Vector2 $pos, array $respawnTimes): ?PickupEntity
    {
        $respawn = $respawnTimes[$entityType] ?? -1;

        return match ($entityType) {
            GameLayer::ENTITY_ARMOR_1       => new PickupEntity($pos, NetworkMessages::POWERUP_ARMOR, respawnTime: $respawn),
            GameLayer::ENTITY_HEALTH_1      => new PickupEntity($pos, NetworkMessages::POWERUP_HEALTH, respawnTime: $respawn),
            GameLayer::ENTITY_WEAPON_SHOTGUN => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_SHOTGUN, respawnTime: $respawn),
            GameLayer::ENTITY_WEAPON_GRENADE => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_GRENADE, respawnTime: $respawn),
            GameLayer::ENTITY_WEAPON_RIFLE  => new PickupEntity($pos, NetworkMessages::POWERUP_WEAPON, GameConstants::WEAPON_RIFLE, respawnTime: $respawn),
            GameLayer::ENTITY_POWERUP_NINJA => new PickupEntity($pos, NetworkMessages::POWERUP_NINJA, respawnTime: $respawn),
            default                         => null,
        };
    }
}