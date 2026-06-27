<?php

namespace TeeFrame\Game\Vote;

/**
 * Result of enforcing a running vote (mirrors CGameContext vote enforcement).
 */
final class VoteEnforce
{
    public const UNKNOWN = 0;
    public const YES     = 1;
    public const NO      = 2;
    public const ABORT   = 3;
    public const CANCEL  = 4;
}
