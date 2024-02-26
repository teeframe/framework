<?php

namespace Network\SnapItems;

use Network\RawPayload;

class ObjGameDataItem extends AbstractSnapItem
{
    public function __construct(
        public int $teamScoreRed,
        public int $teamScoreBlue,
        public int $flagCarrierRedIndex,
        public int $flagCarrierBlueIndex,
    ) {
        parent::__construct(itemId: 7);
    }

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addInt($this->teamScoreRed)
            ->addInt($this->teamScoreBlue)
            ->addInt($this->flagCarrierRedIndex)
            ->addInt($this->flagCarrierBlueIndex)
            ->getPayload();
    }
}
