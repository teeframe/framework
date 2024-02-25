<?php

namespace Network\Encoder\Chunks\Game;

use Network\Encoder\ChunkEncoder;
use Network\Enums\Network;
use Network\Enums\Protocol;

class SvTuneParamsChunk extends ChunkEncoder
{
    public static function make(
        int $groundControlSpeed,
        int $groundControlAccel,
        int $groundFriction,
        int $groundJumpImpulse,
        int $airJumpImpulse,
        int $airControlSpeed,
        int $airControlAccel,
        int $airFriction,
        int $hookLength,
        int $hookFireSpeed,
        int $hookDragAccel,
        int $hookDragSpeed,
        int $gravity,
        int $velrampStart,
        int $velrampRange,
        int $velrampCurvature,
        int $gunCurvature,
        int $gunSpeed,
        int $gunLifetime,
        int $shotgunCurvature,
        int $shotgunSpeed,
        int $shotgunSpeeddiff,
        int $shotgunLifetime,
        int $grenadeCurvature,
        int $grenadeSpeed,
        int $grenadeLifetime,
        int $laserReach,
        int $laserBounceDelay,
        int $laserBounceNum,
        int $laserBounceCost,
        int $laserDamage,
        int $playerCollision,
        int $playerHooking,
    ): static {
        return (new static(Network::CHUNKFLAG_VITAL, Protocol::SV_TUNEPARAMS))
            ->addInt($groundControlSpeed)
            ->addInt($groundControlAccel)
            ->addInt($groundFriction)
            ->addInt($groundJumpImpulse)
            ->addInt($airJumpImpulse)
            ->addInt($airControlSpeed)
            ->addInt($airControlAccel)
            ->addInt($airFriction)
            ->addInt($hookLength)
            ->addInt($hookFireSpeed)
            ->addInt($hookDragAccel)
            ->addInt($hookDragSpeed)
            ->addInt($gravity)
            ->addInt($velrampStart)
            ->addInt($velrampRange)
            ->addInt($velrampCurvature)
            ->addInt($gunCurvature)
            ->addInt($gunSpeed)
            ->addInt($gunLifetime)
            ->addInt($shotgunCurvature)
            ->addInt($shotgunSpeed)
            ->addInt($shotgunSpeeddiff)
            ->addInt($shotgunLifetime)
            ->addInt($grenadeCurvature)
            ->addInt($grenadeSpeed)
            ->addInt($grenadeLifetime)
            ->addInt($laserReach)
            ->addInt($laserBounceDelay)
            ->addInt($laserBounceNum)
            ->addInt($laserBounceCost)
            ->addInt($laserDamage)
            ->addInt($playerCollision)
            ->addInt($playerHooking);
    }
}
