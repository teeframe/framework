<?php

namespace TeeFrame\Game\Vote;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
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
    protected string $voteType        = 'option';
    protected string $voteReason      = '';
    protected int $voteEnforce        = VoteEnforce::UNKNOWN;
    protected bool $voteUpdate        = false;
    protected int $votePos            = 0;

    /**
     * Vote duration in ticks (mirrors g_Config.m_SvVoteTime = 25 seconds).
     */
    protected int $voteDurationTicks = 25 * NetworkParams::TICKS_PER_SECOND;

    /** Minimum delay between votes called by the same player, in ticks (60 seconds). */
    protected int $voteCallDelayTicks = 60 * NetworkParams::TICKS_PER_SECOND;

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
        $option = new VoteOption($description, $command);
        $key    = strtolower($description);

        if (isset($this->voteOptions[$key])) {
            return;
        }

        $this->voteOptions[$key] = $option;
    }

    public function getVoteOption(string $description): ?VoteOption
    {
        return $this->voteOptions[strtolower($description)] ?? null;
    }

    public function removeVoteOption(string $description): void
    {
        $key = strtolower($description);
        unset($this->voteOptions[$key]);
    }

    /** @return list<VoteOption> */
    public function getVoteOptions(): array
    {
        return array_values($this->voteOptions);
    }

    public function clearVoteOptions(): void
    {
        $this->voteOptions = [];
    }

    public function forceResult(bool $yes): void
    {
        if (! $this->isVoteRunning() || in_array($this->voteEnforce, [VoteEnforce::ABORT, VoteEnforce::CANCEL], true)) {
            throw new \InvalidArgumentException('No active vote to force');
        }

        $this->voteEnforce = $yes ? VoteEnforce::YES : VoteEnforce::NO;
        $this->voteUpdate  = false;
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

        $duration = $world->getConfig()->has('sv_world_vote_duration')
            ? $world->getConfig()->getInt('sv_world_vote_duration') * NetworkParams::TICKS_PER_SECOND
            : $this->voteDurationTicks;
        $this->voteType        = 'option';
        $this->voteEnforce     = VoteEnforce::UNKNOWN;
        $this->voteCloseTime   = $world->getCurrentTick() + $duration;
        $this->voteDescription = Utf8::truncateBytes($description, 63);
        $this->voteCommand     = $command;
        $this->voteReason      = Utf8::truncateBytes($reason !== '' ? $reason : 'No reason given', 15);
        $this->voteUpdate      = true;
        $world->consoleInfo('vote', 'Vote started Description="'.$this->voteDescription.'" Reason="'.$this->voteReason.'"');

        foreach ($world->getTees() as $tee) {
            if ($tee instanceof PlayerTee) {
                $tee->vote    = 0;
                $tee->votePos = 0;
            }
        }

        $world->broadcast(new SvVoteSetChunk(
            timeout: (int) ceil($duration / NetworkParams::TICKS_PER_SECOND),
            description: $this->voteDescription,
            reason: $this->voteReason,
        ));
    }

    public function endVote(AbstractWorld $world): void
    {
        if ($this->isVoteRunning()) {
            $world->consoleDebug('vote', 'Vote ended Description="'.$this->voteDescription.'"');
        }

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

        // Spam protection
        if ($world->getConfig()->getInt('sv_world_spam_protection') && $tee->lastVoteTry > 0 && $tee->lastVoteTry + $this->voteTrySpamTicks > $currentTick) {
            return;
        }

        $tee->lastVoteTry = $currentTick;

        if ($tee->team === GameConstants::TEAM_SPECTATORS) {
            $this->sendChat($world, $tee, "Spectators aren't allowed to start a vote.");

            return;
        }

        if ($this->voteCloseTime !== 0) {
            $this->sendChat($world, $tee, 'Wait for current vote to end before calling a new one.');

            return;
        }

        // Per-player call delay (m_LastVoteCall).
        $callDelay = $world->getConfig()->has('sv_world_vote_delay')
            ? $world->getConfig()->getInt('sv_world_vote_delay') * NetworkParams::TICKS_PER_SECOND
            : $this->voteCallDelayTicks;
        $timeLeft = $tee->lastVoteCall > 0
            ? $tee->lastVoteCall + $callDelay - $currentTick
            : 0;

        if ($timeLeft > 0) {
            $this->sendChat($world, $tee, sprintf('You must wait %d seconds before making another vote', (int) ($timeLeft / NetworkParams::TICKS_PER_SECOND) + 1));

            return;
        }

        $reason = Utf8::truncateBytes($reason, 15);
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
            text: Utf8::truncateBytes($announcement, 511),
        ));

        $this->startVote($world, $option->description, $option->command, $reason);
        $this->voteType = $type;

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

        $this->sendChat($world, $tee, "'{$value}' isn't an option on this server");

        return null;
    }

    protected function resolvePlayerVote(AbstractWorld $world, PlayerTee $tee, string $type, string $value): ?VoteOption
    {
        if (! $world->getConfig()->getInt($type === 'kick' ? 'sv_world_vote_kick' : 'sv_world_vote_spectate')) {
            $this->sendChat($world, $tee, $type === 'kick' ? 'Kick voting is disabled.' : 'Spectate voting is disabled.');

            return null;
        }

        $activePlayers = count(array_filter($world->getTees(), fn (AbstractTee $player): bool => $player instanceof PlayerTee && $player->team !== GameConstants::TEAM_SPECTATORS));

        if ($type === 'kick' && $activePlayers < $world->getConfig()->getInt('sv_world_vote_kick_min_players')) {
            $this->sendChat($world, $tee, 'Not enough players for a kick vote.');

            return null;
        }

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

        if ($type === 'kick' && $world->getServer()->getConnection($target)?->rcon()->authenticated) {
            $this->sendChat($world, $tee, "You can't kick an authenticated administrator");

            return null;
        }

        return $type === 'kick'
            ? new VoteOption("Kick '{$target->name}'", "kick {$targetIndex} Kicked by vote")
            : new VoteOption("Move '{$target->name}' to spectators", "set_team {$targetIndex} -1 0");
    }

    /** @return non-empty-list<array{command: AbstractCommand, arguments: list<string>}>|null */
    public function resolveCommands(AbstractWorld $world, string $line): ?array
    {
        try {
            $parts = CommandLine::split($line);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $commands = [];

        foreach ($parts as $part) {
            $command = $world->findCommand(CommandLine::name($part), AbstractCommand::TYPE_SERVER);

            if ($command === null) {
                return null;
            }

            try {
                $arguments = CommandLine::match($command, $part);
            } catch (\InvalidArgumentException) {
                return null;
            }

            if ($arguments === null) {
                return null;
            }

            $commands[] = ['command' => $command, 'arguments' => $arguments];
        }

        return $commands === [] ? null : $commands;
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
            $world->consoleInfo('vote', 'Vote aborted Description="'.$this->voteDescription.'"');
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote aborted'));
            $this->endVote($world);

            return;
        }

        // Cancel (creator left)
        if ($this->voteEnforce === VoteEnforce::CANCEL) {
            $world->consoleInfo('vote', 'Vote canceled Description="'.$this->voteDescription.'"');
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: 'Vote canceled'));
            $this->endVote($world);

            return;
        }

        [$total, $yes, $no] = $this->countVotes($world);

        if ($this->voteUpdate && $this->voteEnforce === VoteEnforce::UNKNOWN) {
            // Majority check (teeworlds 0.6: Yes >= Total/2+1, No >= (Total+1)/2)
            if ($yes >= intdiv($total, 2) + 1) {
                $this->voteEnforce = VoteEnforce::YES;
            } elseif ($no >= intdiv($total + 1, 2)) {
                $this->voteEnforce = VoteEnforce::NO;
            }
        }

        if ($this->voteEnforce === VoteEnforce::YES) {
            try {
                $this->executeVoteCommand($world);
                $world->consoleInfo('vote', 'Vote passed Description="'.$this->voteDescription.'" Yes='.$yes.' No='.$no.' Total='.$total);
                $message = 'Vote passed';
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $world->consoleWarn('vote', 'Vote execution failed Description="'.$this->voteDescription.'"');
                $message = Utf8::truncateBytes('Vote command failed: '.$exception->getMessage(), 511);
            }

            $this->endVote($world);
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: $message));
        } elseif ($this->voteEnforce === VoteEnforce::NO || $currentTick > $this->voteCloseTime) {
            $world->consoleInfo('vote', 'Vote failed Description="'.$this->voteDescription.'" Yes='.$yes.' No='.$no.' Total='.$total.' Reason='.($this->voteEnforce === VoteEnforce::NO ? 'rejected' : 'timeout'));
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

        if (str_starts_with($this->voteCommand, 'kick ') && (int) substr($this->voteCommand, 5) === $teeIndex) {
            $this->voteEnforce = VoteEnforce::ABORT;
        } elseif (str_starts_with($this->voteCommand, 'set_team ') && (int) substr($this->voteCommand, 9) === $teeIndex) {
            $this->voteEnforce = VoteEnforce::ABORT;
        }
    }

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
        $this->requestUpdate();
    }

    public function requestUpdate(): void
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
     * directly, and dispatches to registered server commands otherwise.
     */
    protected function executeVoteCommand(AbstractWorld $world): void
    {
        // Built-in: kick <id> <reason>
        if ($this->voteType === 'kick') {
            $parts  = explode(' ', $this->voteCommand, 3);
            $kickId = (int) ($parts[1] ?? -1);
            $reason = $parts[2] ?? 'Kicked by vote';
            $target = $world->findTee($kickId);

            if ($target !== null && $world->getServer()->getConnection($target)?->rcon()->authenticated) {
                throw new \InvalidArgumentException('Authenticated administrators cannot be kicked by vote');
            }

            $banDuration = $world->getConfig()->getInt('sv_world_vote_kick_ban_duration') * 60;
            $world->getServer()->kick($world, $kickId, $reason, $banDuration);

            return;
        }

        // Built-in: set_team <id> <team> <delay> (spectate vote)
        if ($this->voteType === 'spectate') {
            $parts = explode(' ', $this->voteCommand, 4);
            $tee   = $world->findTee((int) ($parts[1] ?? -1));

            if ($tee instanceof PlayerTee) {
                $tee->setTeam(GameConstants::TEAM_SPECTATORS);
                $tee->teamChangeTick = $world->getCurrentTick() + $world->getConfig()->getInt('sv_world_vote_spectate_rejoin_delay') * 60 * NetworkParams::TICKS_PER_SECOND;
            }

            return;
        }

        $commands = $this->resolveCommands($world, $this->voteCommand)
            ?? throw new \RuntimeException('Vote option does not resolve to valid server commands');
        $caller = $world->findTee($this->voteCreator);

        if ($caller instanceof PlayerTee) {
            foreach ($commands as $resolved) {
                $world->consoleDebug('vote', 'TeeIdx='.$caller->teeIndex.' Vote command='.$resolved['command']->getName());
                $resolved['command']->execute(
                    $world,
                    $caller,
                    $resolved['arguments'],
                    output: static fn (string $message) => $world->consoleInfo('vote', $message),
                );

                if ($world->findTee($caller->teeIndex) !== $caller) {
                    break;
                }
            }
        }
    }

    protected function sendChat(AbstractWorld $world, AbstractTee $tee, string $text): void
    {
        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: Utf8::truncateBytes($text, 511),
        ));
    }
}
