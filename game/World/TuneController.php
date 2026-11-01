<?php

namespace TeeFrame\Game\World;

use TeeFrame\Network\Chunks\Game\SvTuneParamsChunk;

class TuneController
{
    public function __construct(
        public int $groundControlSpeed = 1000,
        public int $groundControlAccel = 200,
        public int $groundFriction = 50,
        public int $groundJumpImpulse = 1320,
        public int $airJumpImpulse = 1200,
        public int $airControlSpeed = 500,
        public int $airControlAccel = 150,
        public int $airFriction = 95,
        public int $hookLength = 38000,
        public int $hookFireSpeed = 8000,
        public int $hookDragAccel = 300,
        public int $hookDragSpeed = 1500,
        public int $gravity = 50,
        public int $velrampStart = 55000,
        public int $velrampRange = 200000,
        public int $velrampCurvature = 140,
        public int $gunCurvature = 125,
        public int $gunSpeed = 220000,
        public int $gunLifetime = 200,
        public int $shotgunCurvature = 125,
        public int $shotgunSpeed = 275000,
        public int $shotgunSpeeddiff = 80,
        public int $shotgunLifetime = 20,
        public int $grenadeCurvature = 700,
        public int $grenadeSpeed = 100000,
        public int $grenadeLifetime = 200,
        public int $laserReach = 80000,
        public int $laserBounceDelay = 15000,
        public int $laserBounceNum = 100,
        public int $laserBounceCost = 0,
        public int $laserDamage = 500,
        public int $playerCollision = 100,
        public int $playerHooking = 100
    ) {}

    /** @return array<string, float> */
    public function getValues(): array
    {
        $values = [];
        foreach (get_object_vars($this) as $name => $value) {
            $key          = strtolower(preg_replace('/[A-Z]/', '_$0', $name) ?? $name);
            $values[$key] = $value / 100.0;
        }

        return $values;
    }

    public function get(string $name): float
    {
        return $this->getValues()[$name] ?? throw new \InvalidArgumentException('Unknown tuning parameter: '.$name);
    }

    public function set(string $name, float $value): void
    {
        $this->get($name);
        $scaled = $value * 100;
        if (! is_finite($scaled) || $scaled < -2147483648 || $scaled > 2147483647) {
            throw new \InvalidArgumentException('Tuning value is outside the protocol integer range');
        }
        $property        = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
        $this->$property = (int) round($scaled);
    }

    public function reset(?string $name = null): void
    {
        $defaults = new self;
        if ($name !== null) {
            $this->set($name, $defaults->get($name));

            return;
        }
        foreach (get_object_vars($defaults) as $property => $value) {
            $this->$property = $value;
        }
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
