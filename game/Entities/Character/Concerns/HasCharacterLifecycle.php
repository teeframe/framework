<?php

namespace TeeFrame\Game\Entities\Character\Concerns;

use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

trait HasCharacterLifecycle
{
    public function spawn(Vector2 $pos, ?AbstractTee $tee = null): void
    {
        $this->health     = 10;
        $this->armor      = 0;
        $this->alive      = true;
        $this->toDestroy  = false;
        $this->tee        = $tee;
        $this->tick       = 0;

        $this->initCore($pos);
        $this->direction = 1;

        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects    = [];

        // Initialize weapons: hammer + gun
        $this->aWeapons = [];
        for ($i = 0; $i < GameConstants::NUM_WEAPONS; $i++) {
            $this->aWeapons[$i] = ['got' => false, 'ammo' => 0];
        }
        $this->aWeapons[GameConstants::WEAPON_HAMMER] = ['got' => true, 'ammo' => -1];
        $this->aWeapons[GameConstants::WEAPON_GUN]    = ['got' => true, 'ammo' => 10];

        $this->activeWeapon = GameConstants::WEAPON_GUN;
        $this->lastWeapon   = GameConstants::WEAPON_HAMMER;
        $this->queuedWeapon = -1;
        $this->reloadTimer  = 0;
        $this->attackTick   = 0;
        $this->inputInitialized = false;
    }

    public function die(int $killerTeeIndex = -1): void
    {
        $this->alive = false;
        $this->markToDestroy();

        // Player die sound
        $this->createSound(GameConstants::SOUND_PLAYER_DIE);

        // Notify game controller for scoring
        $this->world->getGameController()->onCharacterDeath($this, $killerTeeIndex);

        // Set respawn on the tee
        if ($this->tee instanceof PlayerTee) {
            $respawnDelay = $killerTeeIndex === -1 ? 150 : 25; // 3s for self-kill, 0.5s normal
            $this->tee->respawnTick = $this->world->getCurrentTick() + $respawnDelay;
            $this->tee->spawning = true;
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
        $this->ninjaActivationTick = $this->world->getCurrentTick();
        $this->ninjaCurrentMoveTime = 0;
        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects = [];

        $this->aWeapons[GameConstants::WEAPON_NINJA]['got']  = true;
        $this->aWeapons[GameConstants::WEAPON_NINJA]['ammo'] = -1;
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

            // Add death event
            $teeIndex = $this->tee instanceof AbstractTee ? $this->tee->teeIndex : -1;
            $this->world->addEvent(new \TeeFrame\Network\SnapItems\ObjEventDeathItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                clientId: $teeIndex,
            ));
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
