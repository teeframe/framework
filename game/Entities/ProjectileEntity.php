<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

class ProjectileEntity extends AbstractEntity
{
    protected int $startTick = -1;
    protected int $lifeSpan;
    protected float $curvature;
    protected float $speed;

    public function __construct(public Vector2 $position, public Vector2 $direction, public int $type)
    {
        parent::__construct(position: $position);

        // Tuning defaults matching Teeworlds 0.6
        match ($type) {
            1 => [$this->curvature = 1.25, $this->speed = 2200.0, $this->lifeSpan = 100],  // WEAPON_GUN
            2 => [$this->curvature = 1.25, $this->speed = 2750.0, $this->lifeSpan = 10],   // WEAPON_SHOTGUN
            3 => [$this->curvature = 7.0,  $this->speed = 1000.0, $this->lifeSpan = 100],  // WEAPON_GRENADE
            default => [$this->curvature = 0.0, $this->speed = 0.0, $this->lifeSpan = 0],
        };
    }

    public function setWorld(AbstractWorld $world): void
    {
        parent::setWorld($world);

        $this->startTick = $world->getCurrentTick();
    }

    public function getHitBoxRadius(): int
    {
        return 6;
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
            [$hit] = $collision->intersectLine($prevPos, $curPos);
            if ($hit) {
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

    private function getPos(float $time): Vector2
    {
        // CalcPos: Pos + Velocity * Time + Curvature/10000 * (Time*Time)
        $t = $time * $this->speed;

        return new Vector2(
            $this->position->x + $this->direction->x * $t / $this->speed,
            $this->position->y + $this->direction->y * $t / $this->speed + ($this->curvature / 10000.0) * ($t * $t),
        );
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjProjectileItem(
                x: (int) $this->position->x,
                y: (int) $this->position->y,
                velX: (int) ($this->direction->x * 100),
                velY: (int) ($this->direction->y * 100),
                type: $this->type,
                startTick: $this->startTick,
            ),
        ];
    }
}