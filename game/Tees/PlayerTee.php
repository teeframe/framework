<?php

namespace TeeFrame\Game\Tees;

use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\NetworkParams;

class PlayerTee extends AbstractTee
{
    public int $score = 0;

    // Tick when the current score run started
    public int $scoreStartTick = 0;

    // Set when auto-balanced to a new team
    public bool $forceBalanced = false;

    // Last tick with meaningful input activity
    public int $lastActionTick = 0;

    public bool $spawning = false;

    public int $respawnTick = 0;

    public int $dieTick = 0;

    // Team / spectator (CPlayer::m_Team / m_SpectatorID / m_TeamChangeTick)
    public int $team = GameConstants::TEAM_RED;
    public int $spectatorId = GameConstants::SPEC_FREEVIEW;
    public int $teamChangeTick = 0;
    public int $lastSetTeam = 0;
    public int $lastSetSpectatorMode = 0;

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

    // Vote state (CPlayer::m_Vote / m_VotePos / m_LastVoteTry / m_LastVoteCall)
    public int $vote = 0;
    public int $votePos = 0;
    public int $lastVoteTry = 0;
    public int $lastVoteCall = 0;

    protected function getSnapScore(): int
    {
        return $this->score;
    }

    protected function getSnapTeam(): int
    {
        return $this->team;
    }

    protected function getSnapSpectatorId(): int
    {
        return $this->spectatorId;
    }

    public function setTeam(int $team): void
    {
        if ($this->team === $team) {
            return;
        }

        if ($this->character !== null) {
            $this->character->die($this->teeIndex, GameConstants::WEAPON_WORLD);
        }

        $this->team         = $team;
        $this->spectatorId = GameConstants::SPEC_FREEVIEW;

        $world = $this->world;
        if ($world !== null) {
            // we got to wait 0.5 secs before respawning
            $this->respawnTick = $world->getCurrentTick() + (int) (NetworkParams::TICKS_PER_SECOND / 2);
            $this->lastActionTick = $world->getCurrentTick();

            // Broadcast "'<name>' joined the <team>" (mirrors CPlayer::SetTeam)
            $teamName = $world->getGameController()->getTeamName($team);
            $chat = new SvChatChunk(
                team: 0,
                clientId: -1,
                text: "'{$this->name}' joined the {$teamName}",
            );

            foreach ($world->getTees() as $tee) {
                $world->getServer()->sendToTee($world, $tee->teeIndex, $chat);
            }

            // Re-check team balance after a team change (IGameController::CheckTeamBalance)
            $world->getGameController()->checkTeamBalance();
        }
    }
}
