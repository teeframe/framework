<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjEventExplosionItem;

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
        int $owner = -1,
    ) {
        parent::__construct($position, $direction, $type, $owner);

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

                if ($this->type === self::WEAPON_GRENADE) {
                    $this->explode($colPos);
                }

                $this->markToDestroy();

                return;
            }
        }

        $this->lifeSpan--;

        if ($this->lifeSpan < 0) {
            if ($this->type === self::WEAPON_GRENADE) {
                $this->explode($curPos);
            }

            $this->markToDestroy();

            return;
        }

        // Update visual position to current
        $this->position->x = $curPos->x;
        $this->position->y = $curPos->y;
    }

    private function explode(Vector2 $pos): void
    {
        if ($this->world === null) {
            return;
        }

        // Create explosion event (visual effect on client)
        $this->world->addEvent(new ObjEventExplosionItem(
            x: (int) round($pos->x),
            y: (int) round($pos->y),
        ));

        // Find the owner character for damage attribution
        $ownerChar = null;
        foreach ($this->world->getEntities() as $entity) {
            if ($entity instanceof AbstractCharacterEntity && $entity->tee !== null && $entity->tee->teeIndex === $this->owner) {
                $ownerChar = $entity;
                break;
            }
        }

        if ($ownerChar === null) {
            return;
        }

        // Deal damage to characters within explosion radius
        $radius      = 135.0;
        $innerRadius = 48.0;

        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }

            $diff = new Vector2(
                $entity->position->x - $pos->x,
                $entity->position->y - $pos->y,
            );

            $dist = $diff->length();

            $forceDir = new Vector2(0, 1);
            if ($dist > 0.0) {
                $forceDir = $diff->normalize();
            }

            $l   = 1 - max(0, min(1, ($dist - $innerRadius) / ($radius - $innerRadius)));
            $dmg = (int) (6 * $l);

            if ($dmg > 0) {
                $entity->takeDamage(
                    new Vector2($forceDir->x * $dmg * 2, $forceDir->y * $dmg * 2),
                    $dmg,
                    $ownerChar,
                );
            }
        }
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