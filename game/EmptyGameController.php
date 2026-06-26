<?php

namespace TeeFrame\Game;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

class EmptyGameController implements SnapableObject, TickableObject
{
    public function __construct(protected TickHandler $tickHandler)
    {
    }

    public function doTick(): void
    {
    }

    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): void
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
                roundStartTick: $this->tickHandler->get(),
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
