<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;

abstract class AbstractEntity implements SnapableObject, TickableObject
{
    /**
     * @var int[]
     */
    protected array $allocatedSnapIds = [];

    protected bool $toDestroy = false;

    public function __construct(protected AbstractWorld $world, protected Vector2 $position) {}

    public function getPosition(): Vector2
    {
        return $this->position;
    }

    public function setPosition(Vector2 $position): void
    {
        $this->position = $position;
    }

    /** Distance from the viewer to the entity's visible geometry for snapshot culling. */
    public function getSnapDistance(Vector2 $viewPosition): float
    {
        return $viewPosition->distance($this->position);
    }

    abstract public function getHitBoxRadius(): int;

    abstract public function doTick(): void;

    /* Used for characters */
    public function doTickDeferred(): void {}

    /** Transient entities are removed on round reset; persistent entities override this hook. */
    public function reset(): void
    {
        $this->markForDestruction();
    }

    /** Called before a departing tee's index can be reused. */
    public function onTeeLeave(int $teeIndex): void {}

    /** A fixed protocol ID, or null to use IDs allocated from the world pool. */
    public function getFixedSnapId(): ?int
    {
        return null;
    }

    public function doTickPaused(): void
    {
        // no-op by default
    }

    public function gameLayerClipped(Vector2 $position): bool
    {
        $collision = $this->world->getMap()->getCollision();

        if ($collision === null) {
            return false;
        }

        $tileX = (int) ((int) round($position->x) / 32);
        $tileY = (int) ((int) round($position->y) / 32);

        return $tileX < -200 || $tileX > $collision->getWidth() + 200
            || $tileY < -200 || $tileY > $collision->getHeight() + 200;
    }

    public function isMarkedForDestruction(): bool
    {
        return $this->toDestroy;
    }

    public function markForDestruction(): void
    {
        $this->toDestroy = true;
    }

    /**
     * @return int[]
     */
    public function getAllocatedSnapIds(): array
    {
        return $this->allocatedSnapIds;
    }

    public function addAllocatedSnapId(int $id): void
    {
        $this->allocatedSnapIds[] = $id;
    }
}
