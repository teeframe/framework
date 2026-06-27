<?php

namespace TeeFrame\Game\Entities\Character;

class CharacterWeaponState
{
    public function __construct(public bool $got = false, public int $ammo = 0)
    {
    }
}
