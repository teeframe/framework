<?php

namespace TeeFrame\Game;

use TeeFrame\Network\Chunks\Game\SvTuneParamsChunk;

class EmptyTuneController
{
    public function __construct(
       protected int $groundControlSpeed = 1000,
       protected int $groundControlAccel = 200,
       protected int $groundFriction = 50,
       protected int $groundJumpImpulse = 1320,
       protected int $airJumpImpulse = 1200,
       protected int $airControlSpeed = 500,
       protected int $airControlAccel = 150,
       protected int $airFriction = 95,
       protected int $hookLength = 38000,
       protected int $hookFireSpeed = 8000,
       protected int $hookDragAccel = 300,
       protected int $hookDragSpeed = 1500,
       protected int $gravity = 50,
       protected int $velrampStart = 55000,
       protected int $velrampRange = 200000,
       protected int $velrampCurvature = 140,
       protected int $gunCurvature = 125,
       protected int $gunSpeed = 220000,
       protected int $gunLifetime = 200,
       protected int $shotgunCurvature = 125,
       protected int $shotgunSpeed = 275000,
       protected int $shotgunSpeeddiff = 80,
       protected int $shotgunLifetime = 20,
       protected int $grenadeCurvature = 700,
       protected int $grenadeSpeed = 100000,
       protected int $grenadeLifetime = 200,
       protected int $laserReach = 80000,
       protected int $laserBounceDelay = 15000,
       protected int $laserBounceNum = 100,
       protected int $laserBounceCost = 0,
       protected int $laserDamage = 500,
       protected int $playerCollision = 100,
       protected int $playerHooking = 100
    ) {
    }

    public function getTuneParamsChunk(): SvTuneParamsChunk
    {
        return new SvTuneParamsChunk(
            groundControlSpeed: $this->groundControlSpeed,
            groundControlAccel: $this->groundControlAccel,
            groundFriction: $this->groundFriction,
            groundJumpImpulse: $this->groundJumpImpulse,
            airJumpImpulse: $this->airJumpImpulse,
            airControlSpeed: $this->airControlSpeed,
            airControlAccel: $this->airControlAccel,
            airFriction: $this->airFriction,
            hookLength: $this->hookLength,
            hookFireSpeed: $this->hookFireSpeed,
            hookDragAccel: $this->hookDragAccel,
            hookDragSpeed: $this->hookDragSpeed,
            gravity: $this->gravity,
            velrampStart: $this->velrampStart,
            velrampRange: $this->velrampRange,
            velrampCurvature: $this->velrampCurvature,
            gunCurvature: $this->gunCurvature,
            gunSpeed: $this->gunSpeed,
            gunLifetime: $this->gunLifetime,
            shotgunCurvature: $this->shotgunCurvature,
            shotgunSpeed: $this->shotgunSpeed,
            shotgunSpeeddiff: $this->shotgunSpeeddiff,
            shotgunLifetime: $this->shotgunLifetime,
            grenadeCurvature: $this->grenadeCurvature,
            grenadeSpeed: $this->grenadeSpeed,
            grenadeLifetime: $this->grenadeLifetime,
            laserReach: $this->laserReach,
            laserBounceDelay: $this->laserBounceDelay,
            laserBounceNum: $this->laserBounceNum,
            laserBounceCost: $this->laserBounceCost,
            laserDamage: $this->laserDamage,
            playerCollision: $this->playerCollision,
            playerHooking: $this->playerHooking
        );
    }
}