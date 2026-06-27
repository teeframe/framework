<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

/**
 * Vote-only command to change the map.
 *
 * Can be called via the vote menu but not via chat directly.
 */
class ChangeMapCommand extends AbstractCommand
{
    public function __construct(
        protected string $mapName,
        protected string $reason = '',
    ) {
    }

    public function getName(): string
    {
        return 'change_map ' . $this->mapName;
    }

    public function getType(): int
    {
        return self::TYPE_VOTE;
    }

    public function getDescription(): string
    {
        return 'Change map to ' . $this->mapName;
    }

    /**
     * @param  string[]  $matches
     */
    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        // TODO: Implement actual map change (MapChangeChunk broadcast + reload)
        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: 'Map change to ' . $this->mapName . ' requested',
        ));
    }
}
