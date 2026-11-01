<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;

abstract class AbstractCommand
{
    public const TYPE_CHAT   = 1;
    public const TYPE_SERVER = 2;

    /** Bitmask of TYPE_CHAT and/or TYPE_SERVER (passed votes and authenticated RCON) */
    abstract public function getType(): int;

    abstract public function getName(): string;

    /** s: string, r: remaining text, i: integer, f: float; ? makes the remaining parameters optional. */
    abstract public function getParams(): string;

    /** @return list<string> */
    public function getAliases(): array
    {
        return [];
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
     * Default invocation for argument-free vote options.
     * Options with arguments supply their command string when registered.
     */
    public function getCommand(): string
    {
        return $this->getName();
    }

    /** @param list<string> $arguments */
    abstract public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments): void;
}
