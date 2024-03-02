<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjGameInfoItem extends AbstractSnapItem
{
    public function __construct(
        public int $gameFlags,
        public int $gameStateFlags,
        public int $roundStartTick,
        public int $warmupTimer,
        public int $scoreLimit,
        public int $timeLimit,
        public int $roundNum,
        public int $roundCurrent,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_GAMEINFO);
    }

    public function getInts(): array
    {
        return [
            $this->gameFlags,
            $this->gameStateFlags,
            $this->roundStartTick,
            $this->warmupTimer,
            $this->scoreLimit,
            $this->timeLimit,
            $this->roundNum,
            $this->roundCurrent,
        ];
    }
}
