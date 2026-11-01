<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class WhisperCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'whisper';
    }

    public function getDescription(): string
    {
        return 'Send a private message to a player in this world';
    }

    public function getParams(): string
    {
        return 's[player] r[message]';
    }

    public function getAliases(): array
    {
        return ['w'];
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        [$targetName, $whisperMsg] = $arguments;

        $candidates = array_filter(
            $world->getTees(),
            fn (AbstractTee $candidate): bool => strcasecmp($candidate->name, $targetName) === 0,
        );
        $targetTee = array_find($candidates, fn (AbstractTee $candidate): bool => $candidate->name === $targetName);
        if ($targetTee === null && count($candidates) === 1) {
            $targetTee = reset($candidates);
        }

        $server = $world->getServer();

        // Team Number:
        // 0 = global; 1 = team; 2 = sending whisper; 3 = receiving whisper

        if ($targetTee === null) {
            $server->sendToTee($world, $tee->teeIndex, new SvChatChunk(
                team: 0,
                clientId: -1,
                text: count($candidates) > 1
                    ? "Multiple players match '{$targetName}'. Use the exact name."
                    : "Player '{$targetName}' not found",
            ));

            return;
        }

        $this->sendTo($world, $tee, $targetTee, $whisperMsg);
    }

    public function sendTo(AbstractWorld $world, AbstractTee $tee, AbstractTee $targetTee, string $whisperMsg): void
    {
        if ($tee instanceof PlayerTee) {
            $tee->lastWhisperTo = \WeakReference::create($targetTee);
        }
        $server = $world->getServer();
        $server->sendToTee($world, $targetTee->teeIndex, new SvChatChunk(
            team: 2,
            clientId: $tee->teeIndex,
            text: $whisperMsg,
        ));

        $server->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 3,
            clientId: $tee->teeIndex,
            text: $whisperMsg,
        ));
    }
}
