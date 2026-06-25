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
    public int $inputFire       = 0;
    public bool $inputHook      = false;
    public int $inputWantedWeapon = 0;
    public int $inputNextWeapon   = 0;
    public int $inputPrevWeapon   = 0;

    // Previous input for press-counting (CountInput)
    public int $prevInputWantedWeapon = 0;
    public int $prevInputNextWeapon   = 0;
    public int $prevInputPrevWeapon   = 0;
    public int $prevInputFire         = 0;

    // Spam protection (CPlayer::m_LastChat)
    public int $lastChatTick = 0;

    protected function getSnapScore(): int
    {
        return $this->score;
    }
}
