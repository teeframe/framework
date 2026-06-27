<?php

namespace TeeFrame\Game\Vote;

/**
 * A registered vote option (mirrors CVoteOptionServer).
 */
class VoteOption
{
    public function __construct(
        public string $description,
        public string $command,
        public ?VoteOption $next = null,
    ) {
    }
}
