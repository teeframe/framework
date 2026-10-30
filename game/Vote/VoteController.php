<?php

namespace TeeFrame\Game\Vote;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionListAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\NetworkParams;

class VoteController
{
    /** @var array<string, VoteOption> keyed by lowercase description */
    protected array $voteOptions = [];

    protected int $voteCloseTime      = 0;
    protected int $voteCreator        = -1;
    protected string $voteDescription = '';
    protected string $voteCommand     = '';
    protected string $voteReason      = '';
    protected int $voteEnforce        = VoteEnforce::UNKNOWN;
    protected bool $voteUpdate        = false;
    protected int $votePos            = 0;

    /**
     * Vote duration in ticks (mirrors g_Config.m_SvVoteTime = 25 seconds).
     */
    protected int $voteDurationTicks = 25 * NetworkParams::TICKS_PER_SECOND;

    /**
     * Minimum delay between votes called by the same player, in ticks
     * (mirrors g_Config.m_SvVoteDelay = 3 seconds).
     */
    protected int $voteCallDelayTicks = 3 * NetworkParams::TICKS_PER_SECOND;

    /**
     * Spam protection for voting, in ticks
     * (mirrors g_Config.m_SvSpamprotection: 3 seconds between vote tries).
     */
    protected int $voteTrySpamTicks = 3 * NetworkParams::TICKS_PER_SECOND;

    /**
     * @return AbstractChunk[]
     */
    public function getInitialVoteChunks(AbstractTee $requestingTee): array
    {
        return [
            new SvVoteClearOptionsChunk,
            ...$this->getVoteChunks($requestingTee),
        ];
    }

    /**
     * @return AbstractChunk[]
     */
    public function getVoteChunks(AbstractTee $requestingTee): array
    {
        $chunks = [];

        $descriptions = array_map(fn (VoteOption $o) => $o->description, array_values($this->voteOptions));

        // Batch into chunks of up to 15 options (CGameContext::ProgressVoteOptions)
        foreach (array_chunk($descriptions, SvVoteOptionListAddChunk::MAX_OPTIONS) as $batch) {
            $chunks[] = new SvVoteOptionListAddChunk($batch);
        }

        return $chunks;
    }

    public function getRunningVote(AbstractWorld $world): ?AbstractChunk
    {
        if ($this->voteCloseTime === 0) {
            return null;
        }

        return new SvVoteSetChunk(
            timeout: max(0, intdiv($this->voteCloseTime - $world->getCurrentTick(), NetworkParams::TICKS_PER_SECOND)),
            description: $this->voteDescription,
            reason: $this->voteReason,
        );
    }

    public function getRunningVoteStatus(AbstractWorld $world): ?AbstractChunk
    {
        if ($this->voteCloseTime === 0) {
            return null;
        }

        [$total, $yes, $no] = $this->countVotes($world);

        return new SvVoteStatusChunk(
            yes: $yes,
            no: $no,
            pass: $total - ($yes + $no),
            total: $total,
        );
    }

    public function addVoteOption(string $description, string $command): void
    {
        $key = strtolower($description);
        if (isset($this->voteOptions[$key])) {
            return;
        }

        $this->voteOptions[$key] = new VoteOption($description, $command);
    }

    public function removeVoteOption(string $description): void
    {
        $key = strtolower($description);
        unset($this->voteOptions[$key]);
    }

    public function isVoteRunning(): bool
    {
        return $this->voteCloseTime !== 0;
    }

    public function startVote(AbstractWorld $world, string $description, string $command, string $reason): void
    {
        if ($this->voteCloseTime !== 0) {
            return;
        }

        $this->voteEnforce     = VoteEnforce::UNKNOWN;
        $this->voteCloseTime   = $world->getCurrentTick() + $this->voteDurationTicks;
        $this->voteDescription = $description;
        $this->voteCommand     = $command;
        $this->voteReason      = $reason !== '' ? $reason : 'No reason given';
        $this->voteUpdate      = true;

        // Reset all players' votes
        foreach ($world->getTees() as $tee) {
            if ($tee instanceof PlayerTee) {
                $tee->vote    = 0;
                $tee->votePos = 0;
            }
        }

        $world->broadcast(new SvVoteSetChunk(
            timeout: (int) ceil($this->voteDurationTicks / NetworkParams::TICKS_PER_SECOND),
            description: $this->voteDescription,
            reason: $this->voteReason,
        ));
    }

    public function endVote(AbstractWorld $world): void
    {
        $this->voteCloseTime = 0;
        $this->voteCreator   = -1;
        $world->broadcast(new SvVoteSetChunk(
            timeout: 0,
            description: '',
            reason: '',
        ));
    }

    /**
     * Handles a ClCallVote message (NETMSGTYPE_CL_CALLVOTE).
     */
    public function callVote(AbstractWorld $world, AbstractTee $tee, string $type, string $value, string $reason): void
    {
        if (! $tee instanceof PlayerTee) {
            return;
        }

        $currentTick = $world->getCurrentTick();

        // A vote is already running
        if ($this->voteCloseTime !== 0) {
            $this->sendChat($world, $tee, 'Wait for current vote to end before calling a new one.');

            return;
        }

        // Spam protection
        if ($tee->lastVoteTry > 0 && $tee->lastVoteTry + $this->voteTrySpamTicks > $currentTick) {
            return;
        }
        $tee->lastVoteTry = $currentTick;

        // Per-player call delay (m_LastVoteCall + 60s)
        $timeLeft = $tee->lastVoteCall > 0
            ? $tee->lastVoteCall + 60 * NetworkParams::TICKS_PER_SECOND - $currentTick
            : 0;
        if ($timeLeft > 0) {
            $this->sendChat($world, $tee, sprintf('You must wait %d seconds before making another vote', (int) ($timeLeft / NetworkParams::TICKS_PER_SECOND) + 1));

            return;
        }

        $reason = $reason !== '' ? $reason : 'No reason given';

        $type   = strtolower($type);
        $option = match ($type) {
            'option'           => $this->resolveOptionVote($world, $tee, $value),
            'kick', 'spectate' => $this->resolvePlayerVote($world, $tee, $type, $value),
            default            => null,
        };

        if ($option === null) {
            return;
        }

        $announcement = $type === 'option'
            ? "'{$tee->name}' called vote to change server option '{$option->description}' ({$reason})"
            : "'{$tee->name}' called for vote to ".lcfirst($option->description)." ({$reason})";

        $world->broadcast(new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $announcement,
        ));

        $this->startVote($world, $option->description, $option->command, $reason);

        // The caller automatically votes yes
        $tee->vote         = 1;
        $tee->votePos      = ++$this->votePos;
        $this->voteCreator = $tee->teeIndex;
        $tee->lastVoteCall = $currentTick;
    }

    protected function resolveOptionVote(AbstractWorld $world, PlayerTee $tee, string $value): ?VoteOption
    {
        $option = $this->voteOptions[strtolower($value)] ?? null;
        if ($option !== null) {
            return $option;
        }

        $command = $this->findVoteCommand($world, $value, matchDescription: true);
        if ($command === null) {
            $this->sendChat($world, $tee, "'{$value}' isn't an option on this server");

            return null;
        }

        return new VoteOption($command->getDescription(), $command->getCommand());
    }

    protected function resolvePlayerVote(AbstractWorld $world, PlayerTee $tee, string $type, string $value): ?VoteOption
    {
        $targetIndex = (int) $value;
        $target      = $world->findTee($targetIndex);
        $action      = $type === 'kick' ? 'kick' : 'move';

        if ($target === null) {
            $this->sendChat($world, $tee, 'Invalid client id to '.$action);

            return null;
        }

        if ($target === $tee) {
            $this->sendChat($world, $tee, "You can't {$action} yourself");

            return null;
        }

        return $type === 'kick'
            ? new VoteOption("Kick '{$target->name}'", "kick {$targetIndex} Kicked by vote")
            : new VoteOption("Move '{$target->name}' to spectators", "set_team {$targetIndex} -1 0");
    }

    protected function findVoteCommand(AbstractWorld $world, string $value, bool $matchDescription = false): ?AbstractCommand
    {
        foreach ($world->getCommands() as $command) {
            if (! ($command->getType() & AbstractCommand::TYPE_VOTE)) {
                continue;
            }

            if ($command->getCommand() === $value || ($matchDescription && $command->getDescription() === $value)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Handles a ClVote message (NETMSGTYPE_CL_VOTE).
     */
    public function vote(AbstractWorld $world, AbstractTee $tee, int $vote): void
    {
        if (! $tee instanceof PlayerTee) {
            return;
        }

        if ($this->voteCloseTime === 0) {
            return;
        }

        // Player already voted
        if ($tee->vote !== 0) {
            return;
        }

        if ($vote === 0) {
            return;
        }

        $tee->vote        = $vote;
        $tee->votePos     = ++$this->votePos;
        $this->voteUpdate = true;
    }

    /**
     * Vote ticking logic (mirrors CGameContext::OnTick vote section).
     */
    public function tick(AbstractWorld $world): void
    {
        if ($this->voteCloseTime === 0) {
            return;
        }

        $currentTick = $world->getCurrentTick();

        // Abort (e.g. kick target disconnected)
        if ($this->voteEnforce === VoteEnforce::ABORT) {
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote aborted'));
            $this->endVote($world);

            return;
        }

        // Cancel (creator left)
        if ($this->voteEnforce === VoteEnforce::CANCEL) {
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote canceled'));
            $this->endVote($world);

            return;
        }

        [$total, $yes, $no] = $this->countVotes($world);

        if ($this->voteUpdate) {
            // Majority check (teeworlds 0.6: Yes >= Total/2+1, No >= (Total+1)/2)
            if ($yes >= intdiv($total, 2) + 1) {
                $this->voteEnforce = VoteEnforce::YES;
            } elseif ($no >= intdiv($total + 1, 2)) {
                $this->voteEnforce = VoteEnforce::NO;
            }
        }

        if ($this->voteEnforce === VoteEnforce::YES) {
            $this->executeVoteCommand($world);
            $this->endVote($world);
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote passed'));
        } elseif ($this->voteEnforce === VoteEnforce::NO || $currentTick > $this->voteCloseTime) {
            $this->endVote($world);
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote failed'));
        } elseif ($this->voteUpdate) {
            $this->voteUpdate = false;
            $world->broadcast(new SvVoteStatusChunk(
                yes: $yes,
                no: $no,
                pass: $total - ($yes + $no),
                total: $total,
            ));
        }
    }

    /**
     * Called when a tee is removed from the world — aborts a kick/spectate
     * vote targeting that tee (mirrors AbortVoteKickOnDisconnect).
     */
    public function abortVoteOnDisconnect(int $teeIndex): void
    {
        if ($this->voteCloseTime === 0) {
            return;
        }

        // Check if the vote command targets this tee index
        if (str_starts_with($this->voteCommand, 'kick ') && (int) substr($this->voteCommand, 5) === $teeIndex) {
            $this->voteEnforce = VoteEnforce::ABORT;
        } elseif (str_starts_with($this->voteCommand, 'set_team ') && (int) substr($this->voteCommand, 9) === $teeIndex) {
            $this->voteEnforce = VoteEnforce::ABORT;
        }
    }

    /**
     * Called when the vote creator leaves — cancels the vote.
     */
    public function cancelVoteOnCreatorLeave(int $teeIndex): void
    {
        if ($this->voteCloseTime !== 0 && $this->voteCreator === $teeIndex) {
            $this->voteEnforce = VoteEnforce::CANCEL;
        }
    }

    /**
     * Called when a player enters or leaves spectators — the vote count
     * changes, so the next tick should recompute (mirrors m_VoteUpdate = true
     * in OnSetTeamNetMessage).
     */
    public function onTeamChange(PlayerTee $tee): void
    {
        if ($this->voteCloseTime !== 0) {
            $this->voteUpdate = true;
        }
    }

    /**
     * @return array{int, int, int} [total, yes, no]
     */
    protected function countVotes(AbstractWorld $world): array
    {
        $total = 0;
        $yes   = 0;
        $no    = 0;

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }

            // Spectators don't count (mirrors CGameContext::OnTick vote counting)
            if ($tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }

            $total++;
            if ($tee->vote > 0) {
                $yes++;
            } elseif ($tee->vote < 0) {
                $no++;
            }
        }

        return [$total, $yes, $no];
    }

    /**
     * Executes the vote command. Handles built-in commands (kick, set_team)
     * directly, and dispatches to registered vote-type commands otherwise.
     */
    protected function executeVoteCommand(AbstractWorld $world): void
    {
        // Built-in: kick <id> <reason>
        if (str_starts_with($this->voteCommand, 'kick ')) {
            $parts  = explode(' ', $this->voteCommand, 3);
            $kickId = (int) ($parts[1] ?? -1);
            $reason = $parts[2] ?? 'Kicked by vote';
            $world->getServer()->kick($world, $kickId, $reason);

            return;
        }

        // Built-in: set_team <id> <team> <delay> (spectate vote)
        if (str_starts_with($this->voteCommand, 'set_team ')) {
            $parts = explode(' ', $this->voteCommand, 4);
            $tee   = $world->findTee((int) ($parts[1] ?? -1));
            if ($tee instanceof PlayerTee) {
                $tee->setTeam(GameConstants::TEAM_SPECTATORS);
            }

            return;
        }

        $command = $this->findVoteCommand($world, $this->voteCommand);
        $caller  = $world->findTee($this->voteCreator);

        if ($command !== null && $caller instanceof PlayerTee) {
            $command->execute($world, $caller, []);
        }
    }

    protected function sendChat(AbstractWorld $world, AbstractTee $tee, string $text): void
    {
        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: $text,
        ));
    }
}
