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
        if (trim($description) === '' || strlen($description) > 63 || str_contains($description, "\0")) {
            throw new \InvalidArgumentException('Vote descriptions must be non-blank, at most 63 bytes long, and contain no null bytes');
        }
    }
}
