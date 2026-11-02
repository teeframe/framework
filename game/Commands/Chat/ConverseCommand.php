<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;

class ConverseCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'converse';
    }

    public function getAliases(): array
    {
        return ['c'];
    }

    public function getParams(): string
    {
        return 'r[message]';
    }

    public function getDescription(): string
    {
        return 'Whisper to the last player you whispered to';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $target = $tee instanceof PlayerTee ? $tee->lastWhisperTo?->get() : null;

        if ($target === null || ! in_array($target, $world->getTees(), true)) {
            $this->reply($world, $tee, 'No ongoing conversation. Whisper to someone first.', $output);

            return;
        }

        (new WhisperCommand)->sendTo($world, $tee, $target, $arguments[0]);
    }
}
