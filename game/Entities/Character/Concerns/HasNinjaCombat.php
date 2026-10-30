<?php

namespace TeeFrame\Game\Entities\Character\Concerns;

use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;

trait HasNinjaCombat
{
    protected function handleNinja(): void
    {
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();

        // Ninja duration: 15 seconds.
        if (($currentTick - $this->ninjaActivationTick) > (int) (15 * 50)) {
            $this->weapons[GameConstants::WEAPON_NINJA]->got = false;
            $this->activeWeapon                              = $this->lastWeapon;
            $this->setWeapon($this->activeWeapon);

            return;
        }

        $this->setWeapon(GameConstants::WEAPON_NINJA);
        $this->ninjaCurrentMoveTime--;

        if ($this->ninjaCurrentMoveTime === 0) {
            $this->vel->x = $this->ninjaActivationDir->x * $this->ninjaOldVelAmount;
            $this->vel->y = $this->ninjaActivationDir->y * $this->ninjaOldVelAmount;
        }

        if ($this->ninjaCurrentMoveTime <= 0) {
            return;
        }

        $this->moveNinja();
        $this->hitNinjaTargets();
    }

    protected function moveNinja(): void
    {
        $this->vel->x = $this->ninjaActivationDir->x * 50;
        $this->vel->y = $this->ninjaActivationDir->y * 50;

        $collision = $this->world->getMap()->getCollision();
        if ($collision !== null) {
            $collision->moveBox($this->position, $this->vel, new Vector2(self::PHYS_SIZE, self::PHYS_SIZE), 0.0);
        }

        // The dash is server-driven; do not leave velocity for client prediction.
        $this->vel->x = 0.0;
        $this->vel->y = 0.0;
    }

    protected function hitNinjaTargets(): void
    {
        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                continue;
            }
            if (in_array($entity, $this->ninjaHitObjects, true)) {
                continue;
            }
            if ($entity->getPosition()->distance($this->position) > self::PHYS_SIZE * 2.0) {
                continue;
            }

            $this->createSound(GameConstants::SOUND_NINJA_HIT, position: $entity->getPosition());

            if ($this->ninjaNumObjectsHit < 10) {
                $this->ninjaHitObjects[$this->ninjaNumObjectsHit++] = $entity;
            }

            $entity->takeDamage(new Vector2(0, -10.0), 9, $this);
        }
    }

    protected function shootNinja(): int
    {
        $this->ninjaActivationDir   = $this->getAimDirection();
        $this->ninjaCurrentMoveTime = 10; // 200ms at 50 tick/s
        $this->ninjaOldVelAmount    = $this->vel->length();
        $this->ninjaNumObjectsHit   = 0;
        $this->ninjaHitObjects      = [];

        $this->createSound(GameConstants::SOUND_NINJA_FIRE);

        return 40; // 800ms at 50 tick/s
    }
}
