<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class SvKillMsgChunk extends AbstractChunk
{
    public function __construct(
        public int $killer,
        public int $victim,
        public int $weapon,
        public int $modeSpecial = 0,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_KILLMSG);
    }

    public static function make(RawPayload $payload): static
    {
        return new static(
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
            $payload->extractInt(),
        );
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addInt($this->killer)
            ->addInt($this->victim)
            ->addInt($this->weapon)
            ->addInt($this->modeSpecial);
    }
}
