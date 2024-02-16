<?php

namespace Network\Encoder\Chunks\Snap;

use Network\Encoder\PackageChunkSnapEncoder;

class ObjGameInfo extends PackageChunkSnapEncoder
{
    public static function make(int $gameFlags, int $gameStateFlags, int $roundStartTick, int $warmupTimer, int $scoreLimit, int $timeLimit, int $roundNum, int $roundCurrent): static
    {
        return (new static(6))
            ->addInt($gameFlags)
            ->addInt($gameStateFlags)
            ->addInt($roundStartTick)
            ->addInt($warmupTimer)
            ->addInt($scoreLimit)
            ->addInt($timeLimit)
            ->addInt($roundNum)
            ->addInt($roundCurrent);
    }
}