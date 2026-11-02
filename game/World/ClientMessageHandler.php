<?php

namespace TeeFrame\Game\World;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClChangeInfoChunk;
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
        } elseif ($chunk instanceof ClChangeInfoChunk) {
            $this->handleChangeInfoMessage($tee, $chunk);
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
        // Teeworlds 0.6 limits chat to Unicode characters, not bytes.
        $message = trim(Utf8::truncateCharacters(trim($chunk->text), 127));

        if ($message === '') {
            return;
        }

        $connection = $this->world->getServer()->getConnection($tee);
        $mute       = $connection === null ? null : $this->world->getServer()->getChatMutes()->getMute($connection->destinationAddress);

        if ($mute !== null) {
            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvChatChunk(
                team: 0,
                clientId: -1,
                text: 'You are muted for '.max(0, $mute['expiry'] - time()).' more second(s): '.$mute['reason'],
            ));

            return;
        }

        // Spam protection: max ~16 characters per second (CPlayer::m_LastChat).
        if ($tee instanceof PlayerTee) {
            $currentTick = $this->world->getCurrentTick();

            if ($tee->lastChatTick > 0 && $tee->lastChatTick + (int) (NetworkParams::TICKS_PER_SECOND * ((15 + Utf8::length($message)) / 16)) > $currentTick) {
                return;
            }

            $tee->lastChatTick = $currentTick;
        }

        if (str_starts_with($message, '/')) {
            $command = $this->world->findCommand(CommandLine::name(substr($message, 1)), AbstractCommand::TYPE_CHAT);

            if ($command !== null) {
                try {
                    $arguments = CommandLine::match($command, substr($message, 1));
                } catch (\InvalidArgumentException) {
                    $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvChatChunk(
                        team: 0,
                        clientId: -1,
                        text: 'Usage: /'.$command->getName().($command->getParams() !== '' ? ' '.$command->getParams() : ''),
                    ));

                    return;
                }

                if ($arguments !== null) {
                    $this->world->consoleDebug('chat-command', 'TeeIdx='.$tee->teeIndex.' Chat command='.$command->getName());
                    $command->execute($this->world, $tee, $arguments);

                    return;
                }
            }

            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvChatChunk(
                team: 0,
                clientId: -1,
                text: 'Command not found',
            ));

            return;
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
        $this->world->consoleInfo('teamchat', 'Team='.$team.' TeeIdx='.$tee->teeIndex.' Name="'.$tee->name.'": '.$message);

        foreach ($this->world->getTees() as $recipient) {
            $recipientTeam = $recipient instanceof PlayerTee ? $recipient->team : GameConstants::TEAM_RED;

            if ($recipientTeam === $team) {
                $this->world->getServer()->sendToTee($this->world, $recipient->teeIndex, clone $chat);
            }
        }
    }

    protected function handleChangeInfoMessage(AbstractTee $tee, ClChangeInfoChunk $chunk): void
    {
        if (! $tee instanceof PlayerTee) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();

        if ($tee->lastChangeInfo >= 0 && $tee->lastChangeInfo + 5 * NetworkParams::TICKS_PER_SECOND > $currentTick) {
            return;
        }

        $tee->lastChangeInfo = $currentTick;

        $previousName = $tee->name;
        $this->world->setTeeName($tee, $chunk->name);
        $tee->setInfo(
            name: $tee->name,
            clan: $chunk->clan,
            country: $chunk->country,
            skinName: $chunk->skinName,
            useCustomColor: $chunk->useCustomColor,
            colorBody: $chunk->colorBody,
            colorFeet: $chunk->colorFeet,
        );

        if ($tee->name !== $previousName) {
            $this->world->broadcast(new SvChatChunk(
                team: 0,
                clientId: -1,
                text: "'{$previousName}' changed name to '{$tee->name}'",
            ));
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
        if (! $tee instanceof PlayerTee || $this->world->isPaused()) {
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

        if ($controller->teamsLocked && $team !== GameConstants::TEAM_SPECTATORS) {
            $this->world->getServer()->sendToTee($this->world, $tee->teeIndex, new SvBroadcastChunk('Teams are locked'));

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
