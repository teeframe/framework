<?php

namespace TeeFrame\Game\Tees;

class PlayerTee extends AbstractTee
{
    public int $score = 0;

    public bool $spawning = false;

    public int $respawnTick = 0;

    // Latest input from client
    public int $inputDirection  = 0;
    public int $inputTargetX    = 0;
    public int $inputTargetY    = 0;
    public bool $inputJump      = false;
    public bool $inputFire      = false;
    public bool $inputHook      = false;
    public int $inputWantedWeapon = 0;
}
