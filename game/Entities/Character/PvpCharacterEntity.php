<?php

namespace TeeFrame\Game\Entities\Character;

use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjEventDamageIndItem;
use TeeFrame\Network\SnapItems\ObjEventHammerHitItem;

class PvpCharacterEntity extends AbstractCharacterEntity
{
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

            $entity->takeDamage($knockback, 3, $this->tee->teeIndex ?? -1, GameConstants::WEAPON_HAMMER);
            $hits++;
        }

        if ($hits > 0) {
            return intdiv(NetworkParams::TICKS_PER_SECOND, 3);
        }

        return intdiv(125 * NetworkParams::TICKS_PER_SECOND, 1000);
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
            intdiv($tune->gunLifetime * NetworkParams::TICKS_PER_SECOND, 100),
        );

        $this->world->addEntity($proj);

        $this->createSound(GameConstants::SOUND_GUN_FIRE);

        return intdiv(125 * NetworkParams::TICKS_PER_SECOND, 1000);
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
        $tune       = $this->world->getTuneController();
        $speedDiff  = $tune->shotgunSpeeddiff / 100.0;

        for ($i = -$shotSpread; $i <= $shotSpread; $i++) {
            $a         = atan2($dir->y, $dir->x) + $spreading[$i + 2];
            $v         = 1 - (abs($i) / $shotSpread);
            $speed     = $speedDiff + (1.0 - $speedDiff) * $v; // mix(speeddiff, 1.0, v)
            $bulletDir = new Vector2(cos($a) * $speed, sin($a) * $speed);

            $proj = new PvpProjectileEntity($this->world,
                position: clone $projStartPos,
                direction: $bulletDir,
                type: GameConstants::WEAPON_SHOTGUN,
                owner: $this->tee->teeIndex,
            );
            $proj->setTuning(
                $tune->shotgunSpeed / 100.0,
                $tune->shotgunCurvature / 100.0,
                intdiv($tune->shotgunLifetime * NetworkParams::TICKS_PER_SECOND, 100),
            );

            $this->world->addEntity($proj);
        }

        $this->createSound(GameConstants::SOUND_SHOTGUN_FIRE);

        return intdiv(500 * NetworkParams::TICKS_PER_SECOND, 1000);
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
            intdiv($tune->grenadeLifetime * NetworkParams::TICKS_PER_SECOND, 100),
        );

        $this->world->addEntity($proj);

        $this->createSound(GameConstants::SOUND_GRENADE_FIRE);

        return intdiv(500 * NetworkParams::TICKS_PER_SECOND, 1000);
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

        return intdiv(800 * NetworkParams::TICKS_PER_SECOND, 1000);
    }

    protected function handleWeapons(): void
    {
        // Ninja handling runs every tick (duration, dash, hits).
        $this->handleNinja();
        $this->tickAmmoRegen();

        if ($this->reloadTimer > 0) {
            $this->reloadTimer--;

            return;
        }

        $this->fireWeapon();
    }

    protected function fireWeapon(): void
    {
        if ($this->reloadTimer > 0) {
            return;
        }

        $this->doWeaponSwitch();

        $firePressed = $this->countInput($this->latestPrevInput->fire, $this->latestInput->fire) > 0;
        $weaponAmmo  = $this->weapons[$this->activeWeapon]->ammo;

        // Full-auto weapons keep firing while held and there is ammo,
        // even without another packet (CCharacter::FireWeapon in Teeworlds 0.6).
        if (($this->activeWeapon === GameConstants::WEAPON_GRENADE
                || $this->activeWeapon === GameConstants::WEAPON_SHOTGUN
                || $this->activeWeapon === GameConstants::WEAPON_RIFLE)
            && ($this->latestInput->fire & 1)
            && $weaponAmmo !== 0
        ) {
            $firePressed = true;
        }

        if (! $firePressed) {
            return;
        }

        if ($weaponAmmo === 0) {
            // Repeated dry fires must not prevent the gun's regeneration window from starting.
            if ($this->activeWeapon === GameConstants::WEAPON_GUN && $this->ammoRegenStart < 0) {
                $this->ammoRegenStart = $this->world->getCurrentTick();
            }

            $this->reloadTimer = (int) round(125 * NetworkParams::TICKS_PER_SECOND / 1000);

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

        $this->attackTick = $this->world->getCurrentTick();

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

        // A successful shot clears the window; a dry-fire cooldown keeps it running.
        if ($this->reloadTimer > 0 && $this->ammoRegenStart < 0) {
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

        // Regenerate one bullet every 500ms.
        if (($this->tick - $this->ammoRegenStart) >= intdiv(NetworkParams::TICKS_PER_SECOND, 2)) {
            $this->weapons[$this->activeWeapon]->ammo = min(10, $weaponAmmo + 1);
            $this->ammoRegenStart                     = -1;
        }
    }

    public function doTickPaused(): void
    {
        parent::doTickPaused();

        $this->damageTakenTick++;

        if ($this->ammoRegenStart > -1) {
            $this->ammoRegenStart++;
        }
    }

    public function takeDamage(Vector2 $force, int $damage, int $attackerTeeIndex, int $weapon): void
    {
        // Apply knockback first (mirrors CCharacter::TakeDamage: m_Core.m_Vel += Force)
        $this->vel->x += $force->x;
        $this->vel->y += $force->y;

        // Friendly fire check (gamecontroller.cpp L698): in teamplay, same-team
        // hits deal no damage. Self-damage is allowed (handled below).
        $victimTeeIndex = $this->tee !== null ? $this->tee->teeIndex : -1;
        if ($this->world->getGameController()->isFriendlyFire($victimTeeIndex, $attackerTeeIndex)) {
            return;
        }

        // Self-damage is halved (mirrors CCharacter::TakeDamage: Dmg = max(1, Dmg/2))
        if ($attackerTeeIndex >= 0 && $victimTeeIndex === $attackerTeeIndex && $damage > 0) {
            $damage = max(1, (int) ($damage / 2));
        }

        if (! $this->alive || $damage <= 0) {
            parent::takeDamage(new Vector2(0, 0), $damage, $attackerTeeIndex, $weapon);

            return;
        }

        // Emit damage indicators before applying the damage, so the killing blow
        // still shows its stars (mirrors CCharacter::TakeDamage in teeworlds 0.6,
        // where CreateDamageInd runs before the death check).
        $this->damageTaken++;

        $currentTick = $this->world->getCurrentTick();

        if ($currentTick < $this->damageTakenTick + intdiv(NetworkParams::TICKS_PER_SECOND, 2)) {
            // make sure that the damage indicators don't group together
            $this->createDamageInd($this->damageTaken * 0.25, $damage);
        } else {
            $this->damageTaken = 0;
            $this->createDamageInd(0, $damage);
        }

        $this->damageTakenTick = $currentTick;

        // CCharacter::TakeDamage includes spectators following the attacker.
        $attacker = $attackerTeeIndex >= 0 && $attackerTeeIndex !== $victimTeeIndex
            ? $this->world->findTee($attackerTeeIndex)
            : null;
        if ($attacker !== null) {
            $this->world->createSoundGlobalFor(GameConstants::SOUND_HIT, $attackerTeeIndex);
            foreach ($this->world->getTees() as $tee) {
                if ($tee instanceof PlayerTee && $tee->team === GameConstants::TEAM_SPECTATORS && $tee->spectatorId === $attackerTeeIndex) {
                    $this->world->createSoundGlobalFor(GameConstants::SOUND_HIT, $tee->teeIndex);
                }
            }
        }

        // Knockback already applied above; pass zero force to the parent.
        parent::takeDamage(new Vector2(0, 0), $damage, $attackerTeeIndex, $weapon);

        if (! $this->alive && $attacker?->character !== null) {
            $attacker->character->setEmote(GameConstants::EMOTE_HAPPY, $currentTick + NetworkParams::TICKS_PER_SECOND);
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

    protected function handleNinja(): void
    {
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();

        // Ninja duration: 15 seconds.
        if (($currentTick - $this->ninjaActivationTick) > 15 * NetworkParams::TICKS_PER_SECOND) {
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

            if (count($this->ninjaHitObjects) < 10) {
                $this->ninjaHitObjects[] = $entity;
            }

            $entity->takeDamage(new Vector2(0, -10.0), 9, $this->tee->teeIndex ?? -1, GameConstants::WEAPON_NINJA);
        }
    }

    protected function shootNinja(): int
    {
        $this->ninjaActivationDir   = $this->getAimDirection();
        $this->ninjaCurrentMoveTime = intdiv(200 * NetworkParams::TICKS_PER_SECOND, 1000);
        $this->ninjaOldVelAmount    = $this->vel->length();
        $this->ninjaHitObjects      = [];

        $this->createSound(GameConstants::SOUND_NINJA_FIRE);

        return intdiv(800 * NetworkParams::TICKS_PER_SECOND, 1000);
    }

    protected function getAimDirection(): Vector2
    {
        return (new Vector2($this->latestInput->targetX, $this->latestInput->targetY))->normalize();
    }

    protected function getProjectileStartPosition(Vector2 $direction): Vector2
    {
        return $this->position->add($direction->mul(self::PHYS_SIZE * 0.75));
    }
}
