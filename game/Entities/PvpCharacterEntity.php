<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventDamageIndItem;
use TeeFrame\Network\SnapItems\ObjEventHammerHitItem;

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
        $hitRadius = self::PHYS_SIZE * 0.5;

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
            type: PvpProjectileEntity::WEAPON_GUN,
            owner: $this->tee->teeIndex,
        );

        $this->world->addEntity($proj);

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
                type: PvpProjectileEntity::WEAPON_SHOTGUN,
                owner: $this->tee->teeIndex,
            );
            $proj->setTuning(2750.0 * $speed, 1.25, 10);

            $this->world->addEntity($proj);
        }

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
            type: PvpProjectileEntity::WEAPON_GRENADE,
            owner: $this->tee->teeIndex,
        );
        $proj->setTuning(1000.0, 7.0, 100);

        $this->world->addEntity($proj);

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

        return 16; // ~320ms at 50 tick/s
    }

    protected function handleWeapons(bool $firePressed): void
    {
        if (! $firePressed || $this->reloadTimer > 0) {
            return;
        }

        $this->doWeaponSwitch();

        $weaponAmmo = $this->aWeapons[$this->activeWeapon]['ammo'];
        if ($weaponAmmo === 0) {
            $this->reloadTimer = (int) round(125 * 50 / 1000);
            $this->attackTick  = $this->tick;

            return;
        }

        switch ($this->activeWeapon) {
            case self::WEAPON_GUN:
                $this->reloadTimer = $this->shootGun();
                break;
            case self::WEAPON_HAMMER:
                $this->reloadTimer = $this->shootHammer();
                break;
            case self::WEAPON_SHOTGUN:
                $this->reloadTimer = $this->shootShotgun();
                break;
            case self::WEAPON_GRENADE:
                $this->reloadTimer = $this->shootGrenade();
                break;
            case self::WEAPON_RIFLE:
                $this->reloadTimer = $this->shootRifle();
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
}