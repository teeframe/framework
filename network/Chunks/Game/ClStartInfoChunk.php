<?php

namespace Network\Chunks\Game;

use Network\Chunks\AbstractChunk;
use Network\Enums\Network;
use Network\Enums\Protocol;
use Network\RawPayload;

class ClStartInfoChunk extends AbstractChunk
{
    public function __construct(
        public string $name,
        public string $clan,
        public int $country,
        public string $skinName,
        public bool $useCustomColor,
        public int $colorBody,
        public int $colorFeet
    ) {
        parent::__construct(flags: Network::CHUNKFLAG_VITAL, message: Protocol::CL_START_INFO);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractString(),
            $payload->extractString(),
            $payload->extractInt(),
            $payload->extractString(),
            $payload->extractBool(),
            $payload->extractInt(),
            $payload->extractInt()
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->name)
            ->addString($this->clan)
            ->addInt($this->country)
            ->addString($this->skinName)
            ->addBool($this->useCustomColor)
            ->addInt($this->colorBody)
            ->addInt($this->colorFeet);
    }
}
