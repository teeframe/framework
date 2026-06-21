<?php

namespace TeeFrame\Game\Tees;

use TeeFrame\Network\SnapItems\ObjClientInfoItem;
use TeeFrame\Network\SnapItems\ObjPlayerInfoItem;

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

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjClientInfoItem(
                name: $this->name,
                clan: $this->clan,
                country: $this->country,
                skinName: $this->skinName,
                useCustomColor: $this->useCustomColor,
                colorBody: $this->colorBody,
                colorFoot: $this->colorFeet,
            ),
            new ObjPlayerInfoItem(
                local: $this === $requestingTee,
                clientId: $this->teeIndex,
                team: 0,
                score: $this->score,
                latency: 0,
            ),
        ];
    }
}
