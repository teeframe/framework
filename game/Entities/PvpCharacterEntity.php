<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventDamageIndItem;
use TeeFrame\Network\SnapItems\ObjEventHammerHitItem;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

/**
 * PvP character — contains weapon firing logic (hammer, gun) with ammo.
 * Used by PvP game modes (DM, TDM, CTF). DDNet modes extend AbstractCharacterEntity directly.
 */
class PvpCharacterEntity extends AbstractCharacterEntity
{
    public int $damageTaken = 0;
    public int $damageTakenTick = 0;
    protected function shootHammer(): int
    {
        $world = $this->world;
        if ($world === null) {
            return 0;
        }

        $collision = $world->getMap()->getCollision();

        $angle = $this->angle / 256.0;
        $direction = new Vector2(cos($angle), sin($angle));
        $projStartPos = new Vector2(
            $this->position->x + $direction->x * self::PHYS_SIZE * 0.75,
            $this->position->y + $direction->y * self::PHYS_SIZE * 0.75,
        );

        $hits = 0;
        $hitRadius = self::PHYS_SIZE * 1.5; // m_ProximityRadius + m_ProximityRadius*0.5 = 28 + 14 = 42

        $world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_HAMMER_FIRE,
        ));

        foreach ($world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity) {
                continue;
            }
            if ($entity === $this || ! $entity->alive) {
                continue;
            }

            $targetPos = $entity->position;

            if ($projStartPos->distance($targetPos) > $hitRadius) {
                continue;
            }

            if ($collision !== null) {
                [$hit] = $collision->intersectLine($projStartPos, $targetPos);
                if ($hit) {
                    continue;
                }
            }

            $diff = $targetPos->diff($projStartPos);
            if ($diff->length() > 0.0) {
                $hitPos = $targetPos->diff($diff->normalize()->mul(self::PHYS_SIZE * 0.5));
            } else {
                $hitPos = clone $projStartPos;
            }

            $world->addEvent(new ObjEventHammerHitItem(
                x: (int) round($hitPos->x),
                y: (int) round($hitPos->y),
            ));

            $dir = $targetPos->diff($this->position);
            if ($dir->length() > 0.0) {
                $dir = $dir->normalize();
            } else {
                $dir = new Vector2(0, -1);
            }
            $knockback = (new Vector2(0, -1))->add($dir->add(new Vector2(0, -1.1))->normalize()->mul(10.0));

            $entity->takeDamage($knockback, 3, $this);
            $hits++;
        }

        if ($hits > 0) {
            return 16;
        }

        return 6;
    }

    protected function shootGun(): int
    {
        if ($this->world === null || $this->tee === null) {
            return 0;
        }

        $angle = $this->angle / 256.0;
        $dir   = new Vector2(cos($angle), sin($angle));

        $offset = self::PHYS_SIZE * 0.75;
        $proj = new PvpProjectileEntity(
            position: new Vector2(
                $this->position->x + $dir->x * $offset,
                $this->position->y + $dir->y * $offset,
            ),
            direction: $dir,
            type: GameConstants::WEAPON_GUN,
            owner: $this->tee->teeIndex,
        );

        $this->world->addEntity($proj);

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_GUN_FIRE,
        ));

        return 6;
    }

    protected function shootShotgun(): int
    {
        if ($this->world === null || $this->tee === null) {
            return 0;
        }

        $angle  = $this->angle / 256.0;
        $dir    = new Vector2(cos($angle), sin($angle));
        $offset = self::PHYS_SIZE * 0.75;
        $projStartPos = new Vector2(
            $this->position->x + $dir->x * $offset,
            $this->position->y + $dir->y * $offset,
        );

        $shotSpread = 2;
        $spreading = [-0.185, -0.070, 0, 0.070, 0.185];

        for ($i = -$shotSpread; $i <= $shotSpread; $i++) {
            $a = atan2($dir->y, $dir->x) + $spreading[$i + 2];
            $v = 1 - (abs($i) / $shotSpread);
            $speed = 0.8 + (1.0 - 0.8) * $v; // mix(speeddiff, 1.0, v)
            $bulletDir = new Vector2(cos($a), sin($a));

            $proj = new PvpProjectileEntity(
                position: clone $projStartPos,
                direction: $bulletDir,
                type: GameConstants::WEAPON_SHOTGUN,
                owner: $this->tee->teeIndex,
            );
            $proj->setTuning(2750.0 * $speed, 1.25, 10);

            $this->world->addEntity($proj);
        }

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_SHOTGUN_FIRE,
        ));

        return 10; // ~200ms at 50 tick/s
    }

    protected function shootGrenade(): int
    {
        if ($this->world === null || $this->tee === null) {
            return 0;
        }

        $angle  = $this->angle / 256.0;
        $dir    = new Vector2(cos($angle), sin($angle));
        $offset = self::PHYS_SIZE * 0.75;

        $proj = new PvpProjectileEntity(
            position: new Vector2(
                $this->position->x + $dir->x * $offset,
                $this->position->y + $dir->y * $offset,
            ),
            direction: $dir,
            type: GameConstants::WEAPON_GRENADE,
            owner: $this->tee->teeIndex,
        );
        $proj->setTuning(1000.0, 7.0, 100);

        $this->world->addEntity($proj);

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_GRENADE_FIRE,
        ));

        return 10;
    }

    protected function shootRifle(): int
    {
        if ($this->world === null || $this->tee === null) {
            return 0;
        }

        $angle = $this->angle / 256.0;
        $dir   = new Vector2(cos($angle), sin($angle));

        $laser = new PvpLaserEntity(
            position: clone $this->position,
            direction: $dir,
            energy: 800.0,
            owner: $this->tee->teeIndex,
        );

        $this->world->addEntity($laser);

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_RIFLE_FIRE,
        ));

        return 16; // ~320ms at 50 tick/s
    }

    protected function handleWeapons(bool $firePressed): void
    {
        // Ninja handling runs every tick (duration, dash, hits)
        $this->handleNinja();

        if (! $firePressed || $this->reloadTimer > 0) {
            return;
        }

        $this->doWeaponSwitch();

        $weaponAmmo = $this->aWeapons[$this->activeWeapon]['ammo'];
        if ($weaponAmmo === 0) {
            $this->reloadTimer = (int) round(125 * 50 / 1000);

            $this->world?->addEvent(new ObjEventSoundWorldItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                soundId: GameConstants::SOUND_WEAPON_NOAMMO,
            ));

            return;
        }

        switch ($this->activeWeapon) {
            case GameConstants::WEAPON_GUN:
                $this->reloadTimer = $this->shootGun();
                break;
            case GameConstants::WEAPON_HAMMER:
                $this->reloadTimer = $this->shootHammer();
                break;
            case GameConstants::WEAPON_SHOTGUN:
                $this->reloadTimer = $this->shootShotgun();
                break;
            case GameConstants::WEAPON_GRENADE:
                $this->reloadTimer = $this->shootGrenade();
                break;
            case GameConstants::WEAPON_RIFLE:
                $this->reloadTimer = $this->shootRifle();
                break;
            case GameConstants::WEAPON_NINJA:
                $this->reloadTimer = $this->shootNinja();
                break;
        }

        $this->attackTick = $this->tick;

        if ($this->aWeapons[$this->activeWeapon]['ammo'] > 0) {
            $this->aWeapons[$this->activeWeapon]['ammo']--;
        }
    }

    public function takeDamage(Vector2 $force, int $damage, AbstractCharacterEntity $inflictor): void
    {
        parent::takeDamage($force, $damage, $inflictor);

        if (! $this->alive || $this->world === null || $damage <= 0) {
            return;
        }

        $this->damageTaken++;

        $currentTick = $this->world->getCurrentTick();

        if ($currentTick < $this->damageTakenTick + 25) {
            // make sure that the damage indicators don't group together
            $this->createDamageInd($this->damageTaken * 0.25, $damage);
        } else {
            $this->damageTaken = 0;
            $this->createDamageInd(0, $damage);
        }

        $this->damageTakenTick = $currentTick;
    }

    /**
     * Create damage indicator events.
     * Ported from Teeworlds 0.6 CGameContext::CreateDamageInd().
     */
    private function createDamageInd(float $angleMod, int $amount): void
    {
        if ($this->world === null) {
            return;
        }

        $a = 3 * M_PI / 2 + $angleMod;
        $s = $a - M_PI / 3;
        $e = $a + M_PI / 3;

        for ($i = 0; $i < $amount; $i++) {
            $f = $s + ($e - $s) * (($i + 1) / ($amount + 2));

            $this->world->addEvent(new ObjEventDamageIndItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                angle: (int) round($f * 256.0),
            ));
        }
    }

    /**
     * Handle ninja state machine.
     * Ported from Teeworlds 0.6 CCharacter::HandleNinja().
     */
    private function handleNinja(): void
    {
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA || $this->world === null) {
            return;
        }

        $world = $this->world;

        $currentTick = $world->getCurrentTick();

        // Ninja duration: 15 seconds
        if (($currentTick - $this->ninjaActivationTick) > (int) (15 * 50)) {
            // Time's up, return to last weapon
            $this->aWeapons[GameConstants::WEAPON_NINJA]['got'] = false;
            $this->activeWeapon = $this->lastWeapon;

            $this->setWeapon($this->activeWeapon);

            return;
        }

        // Force ninja weapon
        $this->setWeapon(GameConstants::WEAPON_NINJA);

        $this->ninjaCurrentMoveTime--;

        if ($this->ninjaCurrentMoveTime === 0) {
            // Reset velocity
            $this->vel->x = $this->ninjaActivationDir->x * $this->ninjaOldVelAmount;
            $this->vel->y = $this->ninjaActivationDir->y * $this->ninjaOldVelAmount;
        }

        if ($this->ninjaCurrentMoveTime > 0) {
            // Set velocity
            $this->vel->x = $this->ninjaActivationDir->x * 50;
            $this->vel->y = $this->ninjaActivationDir->y * 50;

            $oldPos = clone $this->position;

            $collision = $world->getMap()->getCollision();
            if ($collision !== null) {
                $physSize = self::PHYS_SIZE;
                $collision->moveBox($this->position, $this->vel, new Vector2($physSize, $physSize), 0.0);
            }

            // Reset velocity so the client doesn't predict stuff
            $this->vel->x = 0.0;
            $this->vel->y = 0.0;

            // Check if we hit anything along the way
            $dir    = $this->position->diff($oldPos);
            $radius = self::PHYS_SIZE * 2.0;
            $center = new Vector2($oldPos->x + $dir->x * 0.5, $oldPos->y + $dir->y * 0.5);

            foreach ($world->getEntities() as $entity) {
                if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                    continue;
                }

                // Make sure we haven't hit this object before
                $alreadyHit = false;
                foreach ($this->ninjaHitObjects as $hitObj) {
                    if ($hitObj === $entity) {
                        $alreadyHit = true;
                        break;
                    }
                }
                if ($alreadyHit) {
                    continue;
                }

                // Check so we are sufficiently close
                if ($entity->position->distance($this->position) > self::PHYS_SIZE * 2.0) {
                    continue;
                }

                // Hit a player
                $world->addEvent(new ObjEventSoundWorldItem(
                    x: (int) round($entity->position->x),
                    y: (int) round($entity->position->y),
                    soundId: GameConstants::SOUND_NINJA_HIT,
                ));

                if ($this->ninjaNumObjectsHit < 10) {
                    $this->ninjaHitObjects[$this->ninjaNumObjectsHit++] = $entity;
                }

                $entity->takeDamage(new Vector2(0, -10.0), 9, $this);
            }
        }
    }

    protected function shootNinja(): int
    {
        if ($this->world === null) {
            return 0;
        }

        $angle = $this->angle / 256.0;
        $this->ninjaActivationDir = new Vector2(cos($angle), sin($angle));
        $this->ninjaCurrentMoveTime = 10; // 200ms at 50 tick/s
        $this->ninjaOldVelAmount = $this->vel->length();
        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects = [];

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: GameConstants::SOUND_NINJA_FIRE,
        ));

        return 25; // 500ms firedelay at 50 tick/s
    }
}