<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class SvTuneParamsChunk extends AbstractChunk
{
    public function __construct(
        public int $groundControlSpeed,
        public int $groundControlAccel,
        public int $groundFriction,
        public int $groundJumpImpulse,
        public int $airJumpImpulse,
        public int $airControlSpeed,
        public int $airControlAccel,
        public int $airFriction,
        public int $hookLength,
        public int $hookFireSpeed,
        public int $hookDragAccel,
        public int $hookDragSpeed,
        public int $gravity,
        public int $velrampStart,
        public int $velrampRange,
        public int $velrampCurvature,
        public int $gunCurvature,
        public int $gunSpeed,
        public int $gunLifetime,
        public int $shotgunCurvature,
        public int $shotgunSpeed,
        public int $shotgunSpeeddiff,
        public int $shotgunLifetime,
        public int $grenadeCurvature,
        public int $grenadeSpeed,
        public int $grenadeLifetime,
        public int $laserReach,
        public int $laserBounceDelay,
        public int $laserBounceNum,
        public int $laserBounceCost,
        public int $laserDamage, // Unused by the game
        public int $playerCollision,
        public int $playerHooking,
    )
    {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::SV_TUNEPARAMS);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): array
    {
        return (new RawPayload())
            ->addInt($this->groundControlSpeed)
            ->addInt($this->groundControlAccel)
            ->addInt($this->groundFriction)
            ->addInt($this->groundJumpImpulse)
            ->addInt($this->airJumpImpulse)
            ->addInt($this->airControlSpeed)
            ->addInt($this->airControlAccel)
            ->addInt($this->airFriction)
            ->addInt($this->hookLength)
            ->addInt($this->hookFireSpeed)
            ->addInt($this->hookDragAccel)
            ->addInt($this->hookDragSpeed)
            ->addInt($this->gravity)
            ->addInt($this->velrampStart)
            ->addInt($this->velrampRange)
            ->addInt($this->velrampCurvature)
            ->addInt($this->gunCurvature)
            ->addInt($this->gunSpeed)
            ->addInt($this->gunLifetime)
            ->addInt($this->shotgunCurvature)
            ->addInt($this->shotgunSpeed)
            ->addInt($this->shotgunSpeeddiff)
            ->addInt($this->shotgunLifetime)
            ->addInt($this->grenadeCurvature)
            ->addInt($this->grenadeSpeed)
            ->addInt($this->grenadeLifetime)
            ->addInt($this->laserReach)
            ->addInt($this->laserBounceDelay)
            ->addInt($this->laserBounceNum)
            ->addInt($this->laserBounceCost)
            ->addInt($this->laserDamage)
            ->addInt($this->playerCollision)
            ->addInt($this->playerHooking)
            ->getPayload();
    }
}
