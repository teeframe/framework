<?php

namespace TeeFrame\Game;

/**
 * Immutable snapshot of a single client input frame (mirrors CNetObj_PlayerInput).
 */
final class PlayerInput
{
    public function __construct(
        public readonly int $direction,
        public readonly int $targetX,
        public readonly int $targetY,
        public readonly bool $jump,
        public readonly int $fire,
        public readonly bool $hook,
        public readonly int $playerFlags,
        public readonly int $wantedWeapon,
        public readonly int $nextWeapon,
        public readonly int $prevWeapon,
    ) {
    }
}
