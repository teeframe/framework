<?php

namespace TeeFrame\Game\Commands\Chat;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\NetworkParams;

class EmoteCommand extends AbstractCommand
{
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    public function getName(): string
    {
        return 'emote';
    }

    public function getParams(): string
    {
        return '?s[name] ?i[seconds]';
    }

    public function getDescription(): string
    {
        return 'Set your eye expression: normal, pain, happy, surprise, angry, blink; omit seconds for no expiration';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void
    {
        $emote = match (strtolower($arguments[0] ?? '')) {
            'normal'   => GameConstants::EMOTE_NORMAL,
            'pain'     => GameConstants::EMOTE_PAIN,
            'happy'    => GameConstants::EMOTE_HAPPY,
            'surprise' => GameConstants::EMOTE_SURPRISE,
            'angry'    => GameConstants::EMOTE_ANGRY,
            'blink'    => GameConstants::EMOTE_BLINK,
            default    => null,
        };
        if ($emote === null) {
            $this->reply($world, $tee, $this->getDescription(), $output);

            return;
        }
        $seconds = isset($arguments[1]) ? (int) $arguments[1] : null;
        if ($seconds !== null && ($seconds < 1 || $seconds > 60)) {
            $this->reply($world, $tee, 'Duration must be between 1 and 60 seconds', $output);

            return;
        }
        if ($world->isPaused() || $tee->character === null) {
            $this->reply($world, $tee, 'You need a living character in an unpaused world.', $output);

            return;
        }
        $tee->character->setEmote($emote, $seconds === null ? -1 : $world->getCurrentTick() + $seconds * NetworkParams::TICKS_PER_SECOND);
    }
}
