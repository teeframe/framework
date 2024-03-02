<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

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
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::CL_START_INFO);
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
