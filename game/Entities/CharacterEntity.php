<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

class CharacterEntity extends AbstractEntity
{
    public const PHYS_SIZE = 28;

    public int $health = 10;

    public int $armor = 0;

    public int $activeWeapon = 1; // WEAPON_GUN

    public bool $alive = false;

    public int $tick = 0;

    public int $direction = 0;

    public ?AbstractTee $tee = null;

    public function __construct(public Vector2 $position)
    {
        parent::__construct(position: $position);
    }

    public function __destruct()
    {
        // ...
    }

    public function getHitBoxRadius(): int
    {
        return self::PHYS_SIZE;
    }

    public function spawn(Vector2 $pos, ?AbstractTee $tee = null): void
    {
        $this->position   = $pos;
        $this->health     = 10;
        $this->armor      = 0;
        $this->alive      = true;
        $this->toDestroy  = false;
        $this->tee        = $tee;
        $this->tick       = 0;
    }

    public function die(): void
    {
        $this->alive = false;
        $this->markToDestroy();
    }

    public function doTick(): void
    {
        // ...
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        if (! $this->alive) {
            return [];
        }

        return [
            new ObjCharacterItem(
                tick: $this->tick,
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                velX: 0,
                velY: 0,
                angle: 0,
                direction: $this->direction,
                jumped: 0,
                hookedPlayer: -1,
                hookState: 0,
                hookTick: 0,
                hookX: 0,
                hookY: 0,
                hookDx: 0,
                hookDy: 0,
                playerFlags: 0,
                health: $this->health,
                armor: $this->armor,
                ammoCount: 10,
                weapon: $this->activeWeapon,
                emote: 0,
                attackTick: 0,
            ),
        ];
    }
}
