<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjFlagItem;


class FlagEntity extends AbstractEntity
{
    public const PHYS_SIZE = 14;

    public Vector2 $vel;
    public Vector2 $standPos;

    /** @var AbstractCharacterEntity|null */
    public ?AbstractCharacterEntity $carryingCharacter = null;

    public int $team;
    public bool $atStand = true;
    public int $dropTick = 0;
    public int $grabTick = 0;

    public function __construct(AbstractWorld $world, Vector2 $position, int $team)
    {
        parent::__construct(world: $world, position: clone $position);

        $this->team     = $team;
        $this->standPos = clone $position;
        $this->vel      = new Vector2(0, 0);

        $this->reset();
    }

    public function getHitBoxRadius(): int
    {
        return self::PHYS_SIZE;
    }

    public function reset(): void
    {
        $this->carryingCharacter = null;
        $this->atStand           = true;
        $this->position          = clone $this->standPos;
        $this->vel               = new Vector2(0, 0);
        $this->grabTick          = 0;
    }

    public function tickPaused(): void
    {
        ++$this->dropTick;
        if ($this->grabTick !== 0) {
            ++$this->grabTick;
        }
    }

    public function doTick(): void
    {
        // no-op: handled by the CTF game controller
    }

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjFlagItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                team: $this->team,
            ),
        ];
    }
}
