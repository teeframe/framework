<?php

namespace Network\SnapItems;

use Network\RawPayload;

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
        parent::__construct(itemId: 6);
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addInt($this->gameFlags)
            ->addInt($this->gameStateFlags)
            ->addInt($this->roundStartTick)
            ->addInt($this->warmupTimer)
            ->addInt($this->scoreLimit)
            ->addInt($this->timeLimit)
            ->addInt($this->roundNum)
            ->addInt($this->roundCurrent)
            ->getPayload();
    }
}