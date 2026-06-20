<?php

namespace TeeFrame\Game\Tees;

class PlayerTee extends AbstractTee
{
    public int $score = 0;

    public bool $spawning = false;

    public int $respawnTick = 0;
}
