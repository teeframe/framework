<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Entities\AbstractCharacterEntity;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjPickupItem;

class PickupEntity extends AbstractEntity
{
    private const PICKUP_PHYS_SIZE = 14;

    private int $spawnTick = -1;

    /**
     * @param int $respawnTime Respawn time in ticks after being picked up (-1 = never respawn).
     */
    public function __construct(
        Vector2 $position,
        private int $type,
        private int $subType = 0,
        private int $respawnTime = -1,
    ) {
        parent::__construct(position: $position);
    }

    public function setWorld(AbstractWorld $world): void
    {
        parent::setWorld($world);

        // Pickups are immediately available (spawn delay = 0),
        // matching Teeworlds 0.6 DM datafile defaults.
        $this->spawnTick = -1;
    }

    public function getHitBoxRadius(): int
    {
        return self::PICKUP_PHYS_SIZE;
    }

    public function doTick(): void
    {
        if ($this->world === null) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();

        // Wait for spawn delay
        if ($this->spawnTick > 0 && $currentTick <= $this->spawnTick) {
            return;
        }

        // Check if a character is close enough to pick us up
        foreach ($this->world->getEntities() as $entity) {
                if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }

            $dist = $this->position->distance($entity->position);
            if ($dist > self::PICKUP_PHYS_SIZE + $entity->getHitBoxRadius()) {
                continue;
            }

            $respawnTime = $this->onPickup($entity);

            if ($respawnTime >= 0) {
                $this->spawnTick = $currentTick + $respawnTime;
            }

            break; // Only one pickup per tick
        }
    }

    /**
     * Handle a character picking up this item.
     * Returns respawn time in ticks, or -1 if not picked up.
     */
    private function onPickup(AbstractCharacterEntity $character): int
    {
        $soundId = -1;
        $respawnTime = -1;

        switch ($this->type) {
            case NetworkMessages::POWERUP_HEALTH:
                if ($character->increaseHealth(1)) {
                    $soundId = GameConstants::SOUND_PICKUP_HEALTH;
                    $respawnTime = $this->respawnTime;
                }
                break;

            case NetworkMessages::POWERUP_ARMOR:
                if ($character->increaseArmor(1)) {
                    $soundId = GameConstants::SOUND_PICKUP_ARMOR;
                    $respawnTime = $this->respawnTime;
                }
                break;

            case NetworkMessages::POWERUP_WEAPON:
                if ($this->subType >= 0 && $this->subType < GameConstants::NUM_WEAPONS) {
                    if ($character->giveWeapon($this->subType, 10)) {
                        $soundId = match ($this->subType) {
                            GameConstants::WEAPON_SHOTGUN => GameConstants::SOUND_PICKUP_SHOTGUN,
                            GameConstants::WEAPON_GRENADE => GameConstants::SOUND_PICKUP_GRENADE,
                            default => GameConstants::SOUND_PICKUP_SHOTGUN,
                        };
                        $respawnTime = $this->respawnTime;
                    }
                }
                break;

            case NetworkMessages::POWERUP_NINJA:
                $character->giveNinja();
                $soundId = GameConstants::SOUND_PICKUP_NINJA;
                $respawnTime = $this->respawnTime;
                break;
        }

        if ($soundId >= 0 && $this->world !== null) {
            $this->world->addEvent(new ObjEventSoundWorldItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                soundId: $soundId,
            ));
        }

        return $respawnTime;
    }

    /**
     * @return \TeeFrame\Network\SnapItems\AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        // Don't snap if in spawn delay
        if ($this->spawnTick > 0 && ($this->world === null || $this->world->getCurrentTick() <= $this->spawnTick)) {
            return [];
        }

        return [
            new ObjPickupItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                type: $this->type,
                subType: $this->subType,
            ),
        ];
    }
}