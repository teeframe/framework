<?php

namespace TeeFrame\Game\Entities\Character;

use TeeFrame\Game\Entities\Character\Concerns\HasNinjaCombat;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventDamageIndItem;
use TeeFrame\Network\SnapItems\ObjEventHammerHitItem;

class PvpCharacterEntity extends AbstractCharacterEntity
{
    use HasNinjaCombat;

    public int $damageTaken     = 0;
    public int $damageTakenTick = 0;
    public int $ammoRegenStart  = -1;

    protected function shootHammer(): int
    {
        $collision = $this->world->getMap()->getCollision();

        $projStartPos = $this->getProjectileStartPosition($this->getAimDirection());

        $hits      = 0;
        $hitRadius = self::PHYS_SIZE * 1.5; // m_ProximityRadius + m_ProximityRadius*0.5 = 28 + 14 = 42

        $this->createSound(GameConstants::SOUND_HAMMER_FIRE);

        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity) {
                continue;
            }
            if ($entity === $this || ! $entity->alive) {
                continue;
            }

            $targetPos = $entity->getPosition();

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

            $this->world->addEvent(new ObjEventHammerHitItem(
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
        if ($this->tee === null) {
            return 0;
        }

        $dir = $this->getAimDirection();

        $tune = $this->world->getTuneController();

        $proj = new PvpProjectileEntity(
            world: $this->world,
            position: $this->getProjectileStartPosition($dir),
            direction: $dir,
            type: GameConstants::WEAPON_GUN,
            owner: $this->tee->teeIndex,
        );
        $proj->setTuning(
            $tune->gunSpeed / 100.0,
            $tune->gunCurvature / 100.0,
            (int) ($tune->gunLifetime / 2),
        );

        $this->world->addEntity($proj);

        $this->createSound(GameConstants::SOUND_GUN_FIRE);

        return 6;
    }

    protected function shootShotgun(): int
    {
        if ($this->tee === null) {
            return 0;
        }

        $dir          = $this->getAimDirection();
        $projStartPos = $this->getProjectileStartPosition($dir);

        $shotSpread = 2;
        $spreading  = [-0.185, -0.070, 0, 0.070, 0.185];

        for ($i = -$shotSpread; $i <= $shotSpread; $i++) {
            $a         = atan2($dir->y, $dir->x) + $spreading[$i + 2];
            $v         = 1 - (abs($i) / $shotSpread);
            $speed     = 0.8 + (1.0 - 0.8) * $v; // mix(speeddiff, 1.0, v)
            $bulletDir = new Vector2(cos($a), sin($a));

            $tune = $this->world->getTuneController();

            $proj = new PvpProjectileEntity($this->world,
                position: clone $projStartPos,
                direction: $bulletDir,
                type: GameConstants::WEAPON_SHOTGUN,
                owner: $this->tee->teeIndex,
            );
            $proj->setTuning(
                ($tune->shotgunSpeed / 100.0) * $speed,
                $tune->shotgunCurvature / 100.0,
                (int) ($tune->shotgunLifetime / 2),
            );

            $this->world->addEntity($proj);
        }

        $this->createSound(GameConstants::SOUND_SHOTGUN_FIRE);

        return 25; // 500ms at 50 tick/s
    }

    protected function shootGrenade(): int
    {
        if ($this->tee === null) {
            return 0;
        }

        $dir = $this->getAimDirection();

        $tune = $this->world->getTuneController();

        $proj = new PvpProjectileEntity($this->world,
            position: $this->getProjectileStartPosition($dir),
            direction: $dir,
            type: GameConstants::WEAPON_GRENADE,
            owner: $this->tee->teeIndex,
        );
        $proj->setTuning(
            $tune->grenadeSpeed / 100.0,
            $tune->grenadeCurvature / 100.0,
            (int) ($tune->grenadeLifetime / 2),
        );

        $this->world->addEntity($proj);

        $this->createSound(GameConstants::SOUND_GRENADE_FIRE);

        return 25; // 500ms at 50 tick/s
    }

    protected function shootRifle(): int
    {
        if ($this->tee === null) {
            return 0;
        }

        $dir  = $this->getAimDirection();
        $tune = $this->world->getTuneController();

        $laser = new PvpLaserEntity($this->world,
            position: clone $this->position,
            direction: $dir,
            energy: $tune->laserReach / 100.0,
            owner: $this->tee->teeIndex,
        );

        $this->world->addEntity($laser);

        $this->createSound(GameConstants::SOUND_RIFLE_FIRE);

        return 40; // 800ms at 50 tick/s
    }

    protected function handleWeapons(bool $firePressed): void
    {
        // Ninja handling runs every tick (duration, dash, hits)
        $this->handleNinja();

        // A dry-fire cooldown must not starve regeneration when every ready tick
        // has a new fire press. Successful shots still reset the timer below.
        $this->tickAmmoRegen();

        if (! $firePressed || $this->reloadTimer > 0) {
            return;
        }

        $this->doWeaponSwitch();

        $weaponAmmo = $this->weapons[$this->activeWeapon]->ammo;
        if ($weaponAmmo === 0) {
            $this->reloadTimer = (int) round(125 * 50 / 1000);

            $this->createSound(GameConstants::SOUND_WEAPON_NOAMMO);

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

        if ($this->weapons[$this->activeWeapon]->ammo > 0) {
            $this->weapons[$this->activeWeapon]->ammo--;
        }

        $this->ammoRegenStart = -1;
    }

    protected function tickAmmoRegen(): void
    {
        // Only the gun regenerates ammo
        if ($this->activeWeapon !== GameConstants::WEAPON_GUN) {
            $this->ammoRegenStart = -1;

            return;
        }

        // Pause during reload — don't reset, just wait
        if ($this->reloadTimer > 0) {
            return;
        }

        $weaponAmmo = $this->weapons[$this->activeWeapon]->ammo;
        $weaponGot  = $this->weapons[$this->activeWeapon]->got;

        if (! $weaponGot || $weaponAmmo < 0 || $weaponAmmo >= 10) {
            $this->ammoRegenStart = -1;

            return;
        }

        if ($this->ammoRegenStart < 0) {
            $this->ammoRegenStart = $this->tick;
        }

        // 500ms at 50 tick/s = 25 ticks
        if (($this->tick - $this->ammoRegenStart) >= 25) {
            $this->weapons[$this->activeWeapon]->ammo = min(10, $weaponAmmo + 1);
            $this->ammoRegenStart                     = -1;
        }
    }

    public function tickPaused(): void
    {
        parent::tickPaused();

        $this->damageTakenTick++;

        if ($this->ammoRegenStart > -1) {
            $this->ammoRegenStart++;
        }
    }

    public function takeDamage(Vector2 $force, int $damage, AbstractCharacterEntity $inflictor): void
    {
        // Apply knockback first (mirrors CCharacter::TakeDamage: m_Core.m_Vel += Force)
        $this->vel->x += $force->x;
        $this->vel->y += $force->y;

        // Friendly fire check (gamecontroller.cpp L698): in teamplay, same-team
        // hits deal no damage. Self-damage is allowed (handled below).
        $victimTeeIndex = $this->tee !== null ? $this->tee->teeIndex : -1;
        $killerTeeIndex = $inflictor->tee !== null ? $inflictor->tee->teeIndex : -1;
        if ($this->world->getGameController()->isFriendlyFire($victimTeeIndex, $killerTeeIndex)) {
            return;
        }

        // Self-damage is halved (mirrors CCharacter::TakeDamage: Dmg = max(1, Dmg/2))
        if ($victimTeeIndex === $killerTeeIndex && $damage > 0) {
            $damage = max(1, (int) ($damage / 2));
        }

        if (! $this->alive || $damage <= 0) {
            parent::takeDamage(new Vector2(0, 0), $damage, $inflictor);

            return;
        }

        // Emit damage indicators before applying the damage, so the killing blow
        // still shows its stars (mirrors CCharacter::TakeDamage in teeworlds 0.6,
        // where CreateDamageInd runs before the death check).
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

        // Knockback already applied above; pass zero force to the parent.
        parent::takeDamage(new Vector2(0, 0), $damage, $inflictor);

        // Hit sound: sent only to the attacker (mirrors CCharacter::TakeDamage
        // ctf.cpp L746-756: CreateSound(attacker->m_ViewPos, SOUND_HIT, Mask)).
        // Self-damage and world damage don't emit the hit sound.
        if ($killerTeeIndex >= 0 && $killerTeeIndex !== $victimTeeIndex) {
            $this->world->createSoundGlobalFor(GameConstants::SOUND_HIT, $killerTeeIndex);
        }
    }

    protected function createDamageInd(float $angleMod, int $amount): void
    {
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

    protected function getAimDirection(): Vector2
    {
        $angle = $this->angle / 256.0;

        return new Vector2(cos($angle), sin($angle));
    }

    protected function getProjectileStartPosition(Vector2 $direction): Vector2
    {
        return $this->position->add($direction->mul(self::PHYS_SIZE * 0.75));
    }
}
