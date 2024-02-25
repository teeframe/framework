<?php

namespace Game;

use Base\Connection\ConnectionSlot;
use Base\Server\ServerInstance;
use Network\NetworkParams;
use Network\SnapItems\ObjGameInfoItem;
use Network\SnapItems\ObjPlayerInfoItem;

class GameContext
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

        $this->doSnapshot();

        // TODO: master server stuff
    }

    protected function doSnapshot(): void
    {
        // TODO: DoSnapshot()

        foreach (ServerInstance::getConnectionSlots() as $connection) {
            if ($connection->state !== ConnectionSlot::STATE_INGAME) {
                continue;
            }

            $connection->snaps()->sendSnapItems($this->getCurrentTick(), [
                new ObjGameInfoItem(
                    gameFlags: 0,
                    gameStateFlags: 0,
                    roundStartTick: 0,
                    warmupTimer: 0,
                    scoreLimit: 0,
                    timeLimit: 0,
                    roundNum: 0,
                    roundCurrent: 1
                ),
                new ObjPlayerInfoItem(
                        local: 1,
                        clientId: 0,
                        team: 0,
                        score: 0,
                        latency: 0
                )
            ]);
        }
    }
}
