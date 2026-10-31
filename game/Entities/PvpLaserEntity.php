<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjLaserItem;

class PvpLaserEntity extends AbstractLaserEntity
{
    protected int $bounces = 0;
    protected float $bounceDelay;
    protected int $bounceNum;
    protected float $bounceCost;
    protected int $damage;

    protected Vector2 $from;
    protected int $evalTick = 0;

    public function __construct(
        AbstractWorld $world,
        Vector2 $position,
        Vector2 $direction,
        float $energy,
        int $owner,
    ) {
        parent::__construct($world, $position, $direction, $energy, $owner);

        $tune              = $world->getTuneController();
        $this->bounceDelay = $tune->laserBounceDelay / 100.0;
        $this->bounceNum   = (int) ($tune->laserBounceNum / 100);
        $this->bounceCost  = $tune->laserBounceCost / 100.0;
        $this->damage      = (int) ($tune->laserDamage / 100);

        $this->from     = clone $this->position;
        $this->evalTick = $world->getCurrentTick();
        $this->doBounce();
    }

    public function doTick(): void
    {
        $currentTick = $this->world->getCurrentTick();

        if ($currentTick > $this->evalTick + (int) round(50 * $this->bounceDelay / 1000)) {
            $this->doBounce();
        }
    }

    public function tickPaused(): void
    {
        $this->evalTick++;
    }

    protected function doBounce(): void
    {
        $this->evalTick = $this->world->getCurrentTick();

        if ($this->energy < 0) {
            $this->markToDestroy();

            return;
        }

        $to = new Vector2(
            $this->position->x + $this->direction->x * $this->energy,
            $this->position->y + $this->direction->y * $this->energy,
        );

        $collision = $this->world->getMap()->getCollision();
        if ($collision === null) {
            return;
        }

        [$hit, $colPos] = $collision->intersectLine($this->position, $to);

        if ($hit) {
            // Check if a character was hit before the wall
            if (! $this->hitCharacter($this->position, $colPos)) {
                $this->from     = clone $this->position;
                $this->position = $colPos;

                $tempPos = clone $this->position;
                $tempDir = new Vector2($this->direction->x * 4, $this->direction->y * 4);
                $collision->movePoint($tempPos, $tempDir, 1.0);
                $this->position  = $tempPos;
                $this->direction = (new Vector2($tempDir->x, $tempDir->y))->normalize();

                $this->energy -= $this->position->distance($this->from) + $this->bounceCost;
                $this->bounces++;

                if ($this->bounces > $this->bounceNum) {
                    $this->energy = -1;
                }

                $this->doBounce();
            }
        } elseif (! $this->hitCharacter($this->position, $to)) {
            $this->from     = clone $this->position;
            $this->position = $to;
            $this->energy   = -1;
        }
    }

    protected function hitCharacter(Vector2 $from, Vector2 $to): bool
    {
        $closestDist   = PHP_FLOAT_MAX;
        $closestEntity = null;

        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }

            // Skip owner unless the laser has bounced
            if ($entity->tee !== null && $entity->tee->teeIndex === $this->owner && $this->bounces === 0) {
                continue;
            }

            $radius    = $entity->getHitBoxRadius();
            $projected = $this->closestPointOnLine($from, $to, $entity->getPosition());

            $dist = $projected->distance($entity->getPosition());
            if ($dist <= $radius) {
                $lineDist = $projected->distance($from);
                if ($lineDist < $closestDist) {
                    $closestDist   = $lineDist;
                    $closestEntity = $entity;
                }
            }
        }

        if ($closestEntity !== null) {
            $this->from     = clone $from;
            $this->position = new Vector2(
                $closestEntity->getPosition()->x,
                $closestEntity->getPosition()->y,
            );
            $this->energy = -1;
            $closestEntity->takeDamage(new Vector2(0, 0), $this->damage, $this->owner, GameConstants::WEAPON_RIFLE);

            return true;
        }

        return false;
    }

    protected function closestPointOnLine(Vector2 $lineStart, Vector2 $lineEnd, Vector2 $point): Vector2
    {
        $line = $lineEnd->diff($lineStart);
        // Lasers use a squared-length cutoff; hooks and projectiles use length.
        if ($line->dot($line) < 0.0001) {
            return clone $lineStart;
        }

        return $point->closestPointOnLine($lineStart, $lineEnd);
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
            new ObjLaserItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                fromX: (int) round($this->from->x),
                fromY: (int) round($this->from->y),
                startTick: $this->evalTick,
            ),
        ];
    }
}
