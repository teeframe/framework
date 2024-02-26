<?php

namespace Network\SnapItems;

use Network\RawPayload;

class ObjPlayerInfoItem extends AbstractSnapItem
{
    public function __construct(
        public bool $local,
        public int $clientId,
        public int $team,
        public int $score,
        public int $latency,
    ) {
        parent::__construct(itemId: 10);
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addBool($this->local)
            ->addInt($this->clientId)
            ->addInt($this->team)
            ->addInt($this->score)
            ->addInt($this->latency);
    }
}
