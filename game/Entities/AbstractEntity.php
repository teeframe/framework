<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\GameWorld;
use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Core\Vector2;
use TeeFrame\Game\Player;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractEntity implements SnapableObject
{
    /**
     * @var int[]
     */
    protected array $allocatedSnapIds = [];

    protected bool $toDestroy = false;

    protected ?GameWorld $world = null;

    public function __construct(public Vector2 $position) 
    {
    }

    abstract public function tick(): void;

    abstract public function getHitBoxRadius(): int;

    /**
     * @return AbstractSnapItem[]
     */
    abstract protected function doRawSnap(Player $requestingPlayer): array;

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(Player $requestingPlayer): array
    {
        if ($this->isNotOnScreen($requestingPlayer)) {
            return [];
        }

        return $this->doRawSnap($requestingPlayer);
    }

    public function setWorld(GameWorld $world): void
    {
        $this->world = $world;
    }

    public function isToDestroy(): bool
    {
        return $this->toDestroy;
    }

    public function markToDestroy(): void
    {
        $this->toDestroy = true;
    }

    public function getAllocatedSnapIds(): array
    {
        return $this->allocatedSnapIds;
    }

    public function addAllocatedSnapId(int $id): void
    {
        $this->allocatedSnapIds[] = $id;
    }

    protected function isNotOnScreen(Player $requestingPlayer): bool
    {
        $distance = $requestingPlayer->viewPosition->distance($this->position);

        return $distance > 1100;
    }
}