<?php

namespace TeeFrame\Game;

use TeeFrame\Server\Connection\ConnectionSlot;
use TeeFrame\Server\Server\ServerInstance;
use TeeFrame\Server\SnapInterface;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

class GameContext implements SnapInterface
{
    public function __construct(protected int $currentTick = 0)
    {
    }

    public function getCurrentTick(): int
    {
        return $this->currentTick;
    }

    public function doTick(): void
    {
        $this->currentTick++;

        if ($this->currentTick >= NetworkParams::MAXIMUM_TICK) {
            ServerInstance::shutdown();
        }

        // TODO: apply new input

        // TODO: Implement GameServer()->OnTick()

        $this->constructAndBroadcastSnaps();

        // TODO: master server stuff
    }

    protected function constructAndBroadcastSnaps(): void
    {
        // TODO: DoSnapshot()

        foreach (ServerInstance::getConnectionSlots() as $slotIndex => $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $connection->snaps()->sendItems($this->getCurrentTick(), [
                ...$this->doSnap($slotIndex),
                ...$this->doConnectionsSnap($slotIndex),
            ]);
        }
    }

    public function doSnap(int $indexAsking): array
    {
        return [
            new ObjGameInfoItem(
                gameFlags: 0,
                gameStateFlags: 0,
                roundStartTick: $this->getCurrentTick(),
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

    public function doConnectionsSnap(int $indexAsking): array
    {
        $snaps = [];

        foreach (ServerInstance::getConnectionSlots() as $slotIndex => $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $snaps = [...$snaps, ...$connection->doSnap($indexAsking)];
        }

        return $snaps;
    }
}
