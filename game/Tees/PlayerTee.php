<?php

namespace TeeFrame\Game\Tees;

use TeeFrame\Game\PlayerInput;

class PlayerTee extends AbstractTee
{
    public int $score = 0;

    public bool $spawning = false;

    public int $respawnTick = 0;

    public int $dieTick = 0;

    /**
     * Buffered client inputs keyed by prediction tick (m_aInputs).
     *
     * @var array<int, PlayerInput>
     */
    public array $inputs = [];

    // Spam protection (CPlayer::m_LastChat)
    public int $lastChatTick = 0;

    // Kill cooldown (CPlayer::m_LastKill)
    public int $lastKillTick = 0;

    protected function getSnapScore(): int
    {
        return $this->score;
    }
}
