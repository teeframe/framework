<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;

abstract class AbstractCommand
{
    public const TYPE_CHAT = 1;
    public const TYPE_VOTE = 2;

    abstract public function getName(): string;

    /**
     * Default pattern: matches "/<command>" case-insensitively.
     * Override for commands with arguments.
     */
    public function getPattern(): string
    {
        return '/^\/' . preg_quote($this->getName(), '/') . '$/i';
    }

    /**
     * Bitmask of TYPE_CHAT and/or TYPE_VOTE.
     */
    public function getType(): int
    {
        return self::TYPE_CHAT;
    }

    /**
     * The description shown in the client vote menu
     * (mirrors CVoteOptionServer::m_aDescription).
     * Defaults to the command name.
     */
    public function getDescription(): string
    {
        return $this->getName();
    }

    /**
     * The command string used to identify this command when a vote passes.
     * Defaults to the command name.
     */
    public function getCommand(): string
    {
        return $this->getName();
    }

    /**
     * @param  string[]  $matches
     */
    abstract public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void;
}