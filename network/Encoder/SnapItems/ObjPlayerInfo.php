<?php

namespace Network\Encoder\SnapItems;

use Network\Encoder\SnapItemEncoder;

class ObjPlayerInfo extends SnapItemEncoder
{
    public static function make(int $local, int $clientId, int $team, int $score, int $latency): static
    {
        return (new static(10))
            ->addInt($local)
            ->addInt($clientId)
            ->addInt($team)
            ->addInt($score)
            ->addInt($latency);
    }
}