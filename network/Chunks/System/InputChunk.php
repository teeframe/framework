<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class InputChunk extends AbstractChunk
{
    public function __construct(
        public int $ackGameTick,
        public int $predictionTick,
        public int $inputSize,
        public int $inputDirection,
        public int $inputTargetX,
        public int $inputTargetY,
        public bool $inputJump,
        public bool $inputFire,
        public bool $inputHook,
        public int $inputPlayerFlag,
        public int $inputWantedWeapon,
        public int $inputNextWeapon,
        public int $inputPrevWeapon,
    ) {
        parent::__construct(flags: 0, message: NetworkMessages::INPUT, isSystem: true);
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
            $payload->extractBool(),
            $payload->extractBool(),
            $payload->extractBool(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->ackGameTick)
            ->addInt($this->predictionTick)
            ->addInt($this->inputSize)
            ->addInt($this->inputDirection)
            ->addInt($this->inputTargetX)
            ->addInt($this->inputTargetY)
            ->addBool($this->inputJump)
            ->addBool($this->inputFire)
            ->addBool($this->inputHook)
            ->addInt($this->inputPlayerFlag)
            ->addInt($this->inputWantedWeapon)
            ->addInt($this->inputNextWeapon)
            ->addInt($this->inputPrevWeapon);
    }
}
