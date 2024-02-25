<?php

namespace Network\SnapItems;

use Network\RawPayload;

class ObjPlayerInfoItem extends AbstractSnapItem
{
    public function __construct(
        public int $local,
        public int $clientId,
        public int $team,
        public int $score,
        public int $latency,
    ) {
        parent::__construct(itemId: 10);
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addInt($this->local)
            ->addInt($this->clientId)
            ->addInt($this->team)
            ->addInt($this->score)
            ->addInt($this->latency)
            ->getPayload();
    }
}
