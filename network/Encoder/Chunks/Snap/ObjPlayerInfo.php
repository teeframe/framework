<?php

namespace Network\Encoder\Chunks\Snap;

use Network\Encoder\PackageChunkSnapEncoder;

class ObjPlayerInfo extends PackageChunkSnapEncoder
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