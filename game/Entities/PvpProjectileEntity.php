<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;

/**
 * PvP projectile — contains the collision-based physics.
 * Used by PvP game modes (DM, TDM, CTF).
 */
class PvpProjectileEntity extends AbstractProjectileEntity
{
    public function __construct(
        Vector2 $position,
        Vector2 $direction,
        int $type,
    ) {
        parent::__construct($position, $direction, $type);

        // Default PvP tuning (gun)
        $this->speed     = 2200.0;
        $this->curvature = 1.25;
        $this->lifeSpan  = 100;
    }

    public function doTick(): void
    {
        if ($this->world === null) {
            return;
        }

        $currentTick = $this->world->getCurrentTick();
        $tickSpeed   = 50.0;

        $pt = ($currentTick - $this->startTick - 1) / $tickSpeed;
        $ct = ($currentTick - $this->startTick) / $tickSpeed;

        $prevPos = $this->getPos($pt);
        $curPos  = $this->getPos($ct);

        // Check collision
        $collision = $this->world->getMap()->getCollision();
        if ($collision !== null) {
            [$hit, $colPos] = $collision->intersectLine($prevPos, $curPos);
            if ($hit) {
                $this->position->x = $colPos->x;
                $this->position->y = $colPos->y;
                $this->markToDestroy();

                return;
            }
        }

        $this->lifeSpan--;

        if ($this->lifeSpan < 0) {
            $this->markToDestroy();

            return;
        }

        // Update visual position to current
        $this->position->x = $curPos->x;
        $this->position->y = $curPos->y;
    }

    /**
     * CalcPos: Pos + Velocity * Time + Curvature/10000 * (Time*Time)
     * Uses startPos (initial spawn position), matching original Teeworlds m_Pos.
     * Velocity is normalized direction, Time is multiplied by Speed.
     */
    protected function getPos(float $time): Vector2
    {
        $t = $time * $this->speed;

        return new Vector2(
            $this->startPos->x + $this->direction->x * $t,
            $this->startPos->y + $this->direction->y * $t + ($this->curvature / 10000.0) * ($t * $t),
        );
    }
}