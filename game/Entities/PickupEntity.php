<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjPickupItem;

class PickupEntity extends AbstractEntity
{
    const PICKUP_PHYS_SIZE = 14;

    /** -1 means available; null means consumed without respawning. */
    protected ?int $spawnTick = -1;

    /**
     * @param  int  $respawnTime  Respawn time in ticks after being picked up (-1 = never respawn).
     * @param  int  $spawnDelay  Initial spawn delay in ticks before the pickup first appears (0 = immediately available).
     * @param  bool  $alwaysAvailable  Keep the pickup available after collection, ignoring the respawn time.
     */
    public function __construct(
        AbstractWorld $world,
        Vector2 $position,
        protected int $type,
        protected int $subType = 0,
        protected int $respawnTime = -1,
        protected int $spawnDelay = 0,
        protected bool $alwaysAvailable = false,
    ) {
        parent::__construct(world: $world, position: $position);

        if ($this->spawnDelay > 0) {
            $this->spawnTick = $world->getCurrentTick() + $this->spawnDelay;
        } else {
            $this->spawnTick = -1;
        }
    }

    public function getHitBoxRadius(): int
    {
        return self::PICKUP_PHYS_SIZE;
    }

    public function reset(): void
    {
        if ($this->spawnDelay > 0) {
            $this->spawnTick = $this->world->getCurrentTick() + $this->spawnDelay;
        } else {
            $this->spawnTick = -1;
        }
    }

    public function doTickPaused(): void
    {
        if ($this->spawnTick !== null && $this->spawnTick >= 0) {
            $this->spawnTick++;
        }
    }

    public function doTick(): void
    {
        $currentTick = $this->world->getCurrentTick();

        if ($this->spawnTick === null || $currentTick <= $this->spawnTick) {
            return;
        }

        $closest         = null;
        $closestDistance = PHP_FLOAT_MAX;

        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }

            $dist = $this->position->distance($entity->getPosition());

            if ($dist > self::PICKUP_PHYS_SIZE + $entity->getHitBoxRadius() || $dist >= $closestDistance) {
                continue;
            }

            $closest         = $entity;
            $closestDistance = $dist;
        }

        if ($closest === null) {
            return;
        }

        // Only the nearest character can take the pickup (CPickup::Tick).
        $respawnTime = $this->onPickup($closest);

        if ($respawnTime !== null && ! $this->alwaysAvailable) {
            $this->spawnTick = $respawnTime < 0 ? null : $currentTick + $respawnTime;
        }
    }

    /**
     * Handle a character picking up this item.
     * Returns respawn ticks (-1 = never respawn), or null if not picked up.
     */
    protected function onPickup(AbstractCharacterEntity $character): ?int
    {
        $soundId     = -1;
        $respawnTime = null;

        switch ($this->type) {
            case GameConstants::POWERUP_HEALTH:
                if ($character->increaseHealth(1)) {
                    $soundId     = GameConstants::SOUND_PICKUP_HEALTH;
                    $respawnTime = $this->respawnTime;
                }

                break;

            case GameConstants::POWERUP_ARMOR:
                if ($character->increaseArmor(1)) {
                    $soundId     = GameConstants::SOUND_PICKUP_ARMOR;
                    $respawnTime = $this->respawnTime;
                }

                break;

            case GameConstants::POWERUP_WEAPON:
                if ($this->subType >= 0 && $this->subType < GameConstants::NUM_WEAPONS) {
                    if ($character->giveWeapon($this->subType, 10)) {
                        $soundId = match ($this->subType) {
                            GameConstants::WEAPON_SHOTGUN => GameConstants::SOUND_PICKUP_SHOTGUN,
                            GameConstants::WEAPON_GRENADE => GameConstants::SOUND_PICKUP_GRENADE,
                            default                       => GameConstants::SOUND_PICKUP_SHOTGUN,
                        };
                        $respawnTime = $this->respawnTime;
                    }
                }

                break;

            case GameConstants::POWERUP_NINJA:
                $character->giveNinja();
                $soundId     = GameConstants::SOUND_PICKUP_NINJA;
                $respawnTime = $this->respawnTime;
                break;
        }

        if ($soundId >= 0) {
            $this->world->addEvent(new ObjEventSoundWorldItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                soundId: $soundId,
            ));
        }

        return $respawnTime;
    }

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        if ($this->spawnTick === null || $this->world->getCurrentTick() <= $this->spawnTick) {
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
