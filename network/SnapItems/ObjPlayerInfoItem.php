<?php

namespace Network\SnapItems;

use Network\NetworkMessages;

class ObjPlayerInfoItem extends AbstractSnapItem
{
    public function __construct(
        public bool $local,
        public int $clientId,
        public int $team,
        public int $score,
        public int $latency,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_PLAYERINFO, integers: [
            (int) $this->local,
            $this->clientId,
            $this->team,
            $this->score,
            $this->latency,
        ]);
    }
}
