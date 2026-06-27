<?php

namespace TeeFrame\Game\Entities\Character\Concerns;

use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\CharacterWeaponState;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventDeathItem;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

trait HasCharacterLifecycle
{
    public function spawn(Vector2 $pos, ?AbstractTee $tee = null): void
    {
        $this->health    = 10;
        $this->armor     = 0;
        $this->alive     = true;
        $this->toDestroy = false;
        $this->tee       = $tee;
        $this->tick      = 0;

        if ($tee !== null) {
            $tee->character = $this;
        }

        $this->initCore($pos);
        $this->direction = 1;

        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects    = [];

        // Initialize weapons: hammer + gun
        $this->weapons = [];
        for ($i = 0; $i < GameConstants::NUM_WEAPONS; $i++) {
            $this->weapons[$i] = new CharacterWeaponState(got: false, ammo: 0);
        }
        $this->weapons[GameConstants::WEAPON_HAMMER] = new CharacterWeaponState(got: true, ammo: -1);
        $this->weapons[GameConstants::WEAPON_GUN]    = new CharacterWeaponState(got: true, ammo: 10);

        $this->activeWeapon = GameConstants::WEAPON_GUN;
        $this->lastWeapon   = GameConstants::WEAPON_HAMMER;
        $this->queuedWeapon = -1;
        $this->reloadTimer  = 0;
        $this->attackTick   = 0;
    }

    public function die(int $killerTeeIndex = -1): void
    {
        $this->alive = false;
        $this->markToDestroy();

        if ($this->tee !== null) {
            $this->tee->character = null;
        }

        // Player die sound
        $this->createSound(GameConstants::SOUND_PLAYER_DIE);

        // Death event (bursting tee death effect)
        $teeIndex = $this->tee instanceof AbstractTee ? $this->tee->teeIndex : -1;
        $this->world->addEvent(new ObjEventDeathItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            clientId: $teeIndex,
        ));

        // Notify game controller for scoring
        $this->world->getGameController()->onCharacterDeath($this, $killerTeeIndex);

        // Set respawn on the tee
        if ($this->tee instanceof PlayerTee) {
            $respawnDelay           = $killerTeeIndex === -1 ? 150 : 25; // 3s for self-kill, 0.5s normal
            $this->tee->respawnTick = $this->world->getCurrentTick() + $respawnDelay;
            $this->tee->dieTick     = $this->world->getCurrentTick();
            $this->tee->spawning    = true;
        }
    }

    public function increaseHealth(int $amount): bool
    {
        if ($this->health >= 10) {
            return false;
        }
        $this->health = min(10, $this->health + $amount);

        return true;
    }

    public function increaseArmor(int $amount): bool
    {
        if ($this->armor >= 10) {
            return false;
        }
        $this->armor = min(10, $this->armor + $amount);

        return true;
    }

    public function giveNinja(): void
    {
        $this->ninjaActivationTick  = $this->world->getCurrentTick();
        $this->ninjaCurrentMoveTime = 0;
        $this->ninjaNumObjectsHit   = 0;
        $this->ninjaHitObjects      = [];

        $this->weapons[GameConstants::WEAPON_NINJA]->got  = true;
        $this->weapons[GameConstants::WEAPON_NINJA]->ammo = -1;
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA) {
            $this->lastWeapon = $this->activeWeapon;
        }
        $this->activeWeapon = GameConstants::WEAPON_NINJA;
    }

    public function setEmote(int $emote, int $tick): void
    {
        $this->emote = $emote;
    }

    public function takeDamage(Vector2 $force, int $damage, AbstractCharacterEntity $inflictor): void
    {
        if (! $this->alive) {
            return;
        }

        $this->vel->x += $force->x;
        $this->vel->y += $force->y;

        // Armor absorption
        if ($damage > 0 && $this->armor > 0) {
            if ($damage > 1) {
                $this->health--;
                $damage--;
            }

            if ($damage > $this->armor) {
                $damage -= $this->armor;
                $this->armor = 0;
            } else {
                $this->armor -= $damage;
                $damage = 0;
            }
        }

        $this->health -= $damage;

        // Player pain sound
        if ($damage > 0) {
            $this->createSound($damage > 2 ? GameConstants::SOUND_PLAYER_PAIN_LONG : GameConstants::SOUND_PLAYER_PAIN_SHORT);
        }

        if ($this->health <= 0) {
            $killerTeeIndex = $inflictor->tee !== null ? $inflictor->tee->teeIndex : -1;
            $this->die($killerTeeIndex);
        }
    }

    protected function createSound(int $soundId): void
    {
        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: $soundId,
        ));
    }
}
