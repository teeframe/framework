<?php

namespace Game;

use Base\Connection\ConnectionSlot;
use Base\Server\ServerInstance;
use Network\Encoder\Chunks\Snap\EmptySnapChunk;

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

            $connection->addChunk(
                EmptySnapChunk::make($this->getCurrentTick(), $this->getCurrentTick() + (-1))
            )->sendChunks();
        }
    }
}
