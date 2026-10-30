<?php

namespace TeeFrame\Game\World;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClEmoticonChunk;
use TeeFrame\Network\Chunks\Game\ClKillChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClSetSpectatorModeChunk;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvEmoticonChunk;
use TeeFrame\Network\NetworkParams;

class ClientMessageHandler
{
    public function __construct(protected AbstractWorld $world) {}

    public function handle(AbstractTee $tee, AbstractChunk $chunk): void
    {
        if ($chunk instanceof ClSayChunk) {
            $this->handleChatMessage($tee, $chunk);
        } elseif ($chunk instanceof ClEmoticonChunk) {
            $this->handleEmoticonMessage($tee, $chunk);
        } elseif ($chunk instanceof ClKillChunk) {
            $this->handleKillMessage($tee, $chunk);
        } elseif ($chunk instanceof ClSetTeamChunk) {
            $this->handleSetTeamMessage($tee, $chunk);
        } elseif ($chunk instanceof ClSetSpectatorModeChunk) {
            $this->handleSetSpectatorModeMessage($tee, $chunk);
        } elseif ($chunk instanceof ClCallVoteChunk) {
            $this->world->getVoteController()->callVote($this->world, $tee, $chunk->type, $chunk->value, $chunk->reason);
        } elseif ($chunk instanceof ClVoteChunk) {
            $this->world->getVoteController()->vote($this->world, $tee, $chunk->vote);
        }
    }

    protected function handleChatMessage(AbstractTee $tee, ClSayChunk $chunk): void
    {
        $message = trim($chunk->text);
        if ($message === '') {
            return;
        }

        // Spam protection: max ~16 characters per second (CPlayer::m_LastChat).
        if ($tee instanceof PlayerTee) {
            $currentTick = $this->world->getCurrentTick();
            if ($tee->lastChatTick > 0 && $tee->lastChatTick + (int) (50 * ((15 + strlen($message)) / 16)) > $currentTick) {
                return;
            }
            $tee->lastChatTick = $currentTick;
        }

        foreach ($this->world->getCommands() as $command) {
            if (! ($command->getType() & AbstractCommand::TYPE_CHAT)) {
                continue;
            }
            if (preg_match($command->getPattern(), $message, $matches)) {
                $command->execute($this->world, $tee, $matches);

                return;
            }
        }

        $chat = new SvChatChunk(
            team: $chunk->team ? 1 : 0,
            clientId: $tee->teeIndex,
            text: $message,
        );

        if (! $chunk->team) {
            $this->world->broadcast($chat);

            return;
        }

        $team = $tee instanceof PlayerTee ? $tee->team : GameConstants::TEAM_RED;
        foreach ($this->world->getTees() as $recipient) {
            $recipientTeam = $recipient instanceof PlayerTee ? $recipient->team : GameConstants::TEAM_RED;
            if ($recipientTeam === $team) {
                $this->world->getServer()->sendToTee($this->world, $recipient->teeIndex, clone $chat);
            }
        }
    }

    protected function handleEmoticonMessage(AbstractTee $tee, ClEmoticonChunk $chunk): void
    {
        $this->world->broadcast(new SvEmoticonChunk(
            clientId: $tee->teeIndex,
            emoticon: $chunk->emoticon,
        ));
    }

    protected function handleKillMessage(AbstractTee $tee, ClKillChunk $chunk): void
    {
        if (! $tee instanceof PlayerTee) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();
        if ($tee->lastKillTick > 0 && $tee->lastKillTick + NetworkParams::TICKS_PER_SECOND * 3 > $currentTick) {
            return;
        }
        $tee->lastKillTick = $currentTick;

        // WEAPON_SELF preserves the self-kill message and three-second respawn penalty.
        $tee->character?->die($tee->teeIndex, GameConstants::WEAPON_SELF);
    }

    protected function handleSetTeamMessage(AbstractTee $tee, ClSetTeamChunk $chunk): void
    {
        if (! $tee instanceof PlayerTee || $this->world->isPaused()) {
            return;
        }

        $controller  = $this->world->getGameController();
        $team        = $controller->clampTeam($chunk->team);
        $currentTick = $this->world->getCurrentTick();
        if ($tee->team === $team || ($tee->lastSetTeam > 0 && $tee->lastSetTeam + NetworkParams::TICKS_PER_SECOND * 3 > $currentTick)) {
            return;
        }

        if ($tee->teamChangeTick > $currentTick) {
            $tee->lastSetTeam = $currentTick;
            $timeLeft         = (int) (($tee->teamChangeTick - $currentTick) / NetworkParams::TICKS_PER_SECOND);
            $mins             = (int) ($timeLeft / 60);
            $secs             = $timeLeft % 60;
            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvBroadcastChunk(
                sprintf('Time to wait before changing team: %02d:%02d', $mins, $secs),
            ));

            return;
        }

        if (! $controller->canJoinTeam($team, $tee->teeIndex)) {
            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvBroadcastChunk(
                sprintf('Only %d active players are allowed', $this->world->getSlotsLimit() - $controller->getSpectatorSlots()),
            ));

            return;
        }

        if (! $controller->canChangeTeam($tee, $team)) {
            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvBroadcastChunk(
                'Teams must be balanced, please join other team',
            ));

            return;
        }

        $tee->lastSetTeam = $currentTick;
        $tee->setTeam($team);
        $tee->teamChangeTick = $currentTick;
    }

    protected function handleSetSpectatorModeMessage(AbstractTee $tee, ClSetSpectatorModeChunk $chunk): void
    {
        if (! $tee instanceof PlayerTee || $tee->team !== GameConstants::TEAM_SPECTATORS) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();
        if ($tee->spectatorId === $chunk->spectatorId
            || $tee->teeIndex === $chunk->spectatorId
            || ($tee->lastSetSpectatorMode > 0 && $tee->lastSetSpectatorMode + NetworkParams::TICKS_PER_SECOND * 3 > $currentTick)
        ) {
            return;
        }
        $tee->lastSetSpectatorMode = $currentTick;

        if ($chunk->spectatorId !== GameConstants::SPEC_FREEVIEW) {
            $target = $this->world->findTee($chunk->spectatorId);
            if ($target === null || ($target instanceof PlayerTee && $target->team === GameConstants::TEAM_SPECTATORS)) {
                $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvChatChunk(
                    team: 0,
                    clientId: -1,
                    text: 'Invalid spectator id used',
                ));

                return;
            }
        }

        $tee->spectatorId = $chunk->spectatorId;
    }
}
