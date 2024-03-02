<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkMessages;

class ObjGameDataItem extends AbstractSnapItem
{
    public function __construct(
        public int $teamScoreRed,
        public int $teamScoreBlue,
        public int $flagCarrierRedIndex,
        public int $flagCarrierBlueIndex,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_GAMEDATA);
    }

    public function getInts(): array
    {
        return [
            $this->teamScoreRed,
            $this->teamScoreBlue,
            $this->flagCarrierRedIndex,
            $this->flagCarrierBlueIndex,
        ];
    }
}
