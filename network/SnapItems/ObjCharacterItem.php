<?php

namespace Network\SnapItems;

use Network\NetworkMessages;

class ObjCharacterItem extends AbstractSnapItem
{
    public function __construct(
        public int $tick,
        public int $x,
        public int $y,
        public int $velX,
        public int $velY,
        public int $angle,
        public int $direction,
        public int $jumped,
        public int $hookedPlayer,
        public int $hookState,
        public int $hookTick,
        public int $hookX,
        public int $hookY,
        public int $hookDx,
        public int $hookDy,
        // Non-core
        public int $playerFlags,
        public int $health,
        public int $armor,
        public int $ammoCount,
        public int $weapon,
        public int $emote,
        public int $attackTick,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_CHARACTER, integers: [
            $this->tick,
            $this->x,
            $this->y,
            $this->velX,
            $this->velY,
            $this->angle,
            $this->direction,
            $this->jumped,
            $this->hookedPlayer,
            $this->hookState,
            $this->hookTick,
            $this->hookX,
            $this->hookY,
            $this->hookDx,
            $this->hookDy,
            // Non-core
            $this->playerFlags,
            $this->health,
            $this->armor,
            $this->ammoCount,
            $this->weapon,
            $this->emote,
            $this->attackTick,
        ]);
    }
}
