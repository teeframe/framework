<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

class AbstractWorldController implements SnapableObject
{
    public function __construct(protected AbstractWorld $world)
    {
    }

    public function doTick(): void
    {
    }    

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjGameInfoItem(
                gameFlags: 0,
                gameStateFlags: 0,
                roundStartTick: $this->world->getCurrentTick(),
                warmupTimer: 0,
                scoreLimit: 0,
                timeLimit: 0,
                roundNum: 0,
                roundCurrent: 1
            ),
            // new ObjGameDataItem(
            //     teamScoreRed: 0,
            //     teamScoreBlue: 0,
            //     flagCarrierRedIndex: -1,
            //     flagCarrierBlueIndex: -1,
            // )
        ];
    }
}
