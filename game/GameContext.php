<?php

namespace Game;

use Base\Connection\ConnectionSlot;
use Base\Server\ServerInstance;
use Network\Encoder\PackageChunkEncoder;
use Network\Enums\Protocol;

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
                PackageChunkEncoder::make(0, Protocol::SNAPEMPTY)
                    ->addInt($this->getCurrentTick())
                    ->addInt($this->getCurrentTick() + (-1))
            )->sendChunks();
        }
    }
}
