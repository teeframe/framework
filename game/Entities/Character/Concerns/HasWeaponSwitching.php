<?php

namespace TeeFrame\Game\Entities\Character\Concerns;

use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;

trait HasWeaponSwitching
{
    public function setWeapon(int $weapon): void
    {
        if ($weapon === $this->activeWeapon) {
            return;
        }

        $this->lastWeapon   = $this->activeWeapon;
        $this->queuedWeapon = GameConstants::NO_WEAPON;
        $this->activeWeapon = $weapon;

        $this->createSound(GameConstants::SOUND_WEAPON_SWITCH);

        if ($this->activeWeapon < 0 || $this->activeWeapon >= GameConstants::NUM_WEAPONS) {
            $this->activeWeapon = 0;
        }
    }

    public function giveWeapon(int $weapon, int $ammo): bool
    {
        if ($weapon < 0 || $weapon >= GameConstants::NUM_WEAPONS) {
            return false;
        }

        if ($this->weapons[$weapon]->ammo < 10 || ! $this->weapons[$weapon]->got) {
            $this->weapons[$weapon]->got  = true;
            $this->weapons[$weapon]->ammo = min(10, $ammo);

            return true;
        }

        return false;
    }

    protected function countInput(int $prev, int $cur): int
    {
        $prev &= self::INPUT_STATE_MASK;
        $cur &= self::INPUT_STATE_MASK;
        $presses = 0;
        $i       = $prev;

        while ($i !== $cur) {
            $i = ($i + 1) & self::INPUT_STATE_MASK;
            if ($i & 1) {
                $presses++;
            }
        }

        return $presses;
    }

    protected function handleWeaponSwitch(): void
    {
        if (! $this->tee instanceof PlayerTee) {
            return;
        }

        $wantedWeapon = $this->activeWeapon;
        if ($this->queuedWeapon !== GameConstants::NO_WEAPON) {
            $wantedWeapon = $this->queuedWeapon;
        }

        $next = $this->countInput($this->latestPrevInput->nextWeapon, $this->latestInput->nextWeapon);
        $prev = $this->countInput($this->latestPrevInput->prevWeapon, $this->latestInput->prevWeapon);

        if ($next < 128) {
            while ($next > 0) {
                $wantedWeapon = ($wantedWeapon + 1) % GameConstants::NUM_WEAPONS;
                if ($this->weapons[$wantedWeapon]->got) {
                    $next--;
                }
            }
        }

        if ($prev < 128) {
            while ($prev > 0) {
                $wantedWeapon = ($wantedWeapon - 1) < 0 ? GameConstants::NUM_WEAPONS - 1 : $wantedWeapon - 1;
                if ($this->weapons[$wantedWeapon]->got) {
                    $prev--;
                }
            }
        }

        // Direct weapon selection (1-indexed from client, convert to 0-indexed)
        if ($this->latestInput->wantedWeapon > 0) {
            $wantedWeapon = $this->latestInput->wantedWeapon - 1;
        }

        if ($wantedWeapon >= 0 && $wantedWeapon < GameConstants::NUM_WEAPONS
            && $wantedWeapon !== $this->activeWeapon
            && $this->weapons[$wantedWeapon]->got
        ) {
            $this->queuedWeapon = $wantedWeapon;
        }

        $this->doWeaponSwitch();
    }

    protected function doWeaponSwitch(): void
    {
        if ($this->reloadTimer !== 0 || $this->queuedWeapon === GameConstants::NO_WEAPON || $this->weapons[GameConstants::WEAPON_NINJA]->got) {
            return;
        }

        $this->setWeapon($this->queuedWeapon);
    }
}
