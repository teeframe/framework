<?php

namespace TeeFrame\Map;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\MapLayers\GameLayer;

class Collision
{
    public const COLFLAG_SOLID  = 1;
    public const COLFLAG_DEATH  = 2;
    public const COLFLAG_NOHOOK = 4;

    /**
     * @var array<int, int>
     */
    protected array $tiles = [];

    protected int $width  = 0;
    protected int $height = 0;

    public function __construct()
    {
    }

    public function init(GameLayer $gameLayer): void
    {
        $this->width  = $gameLayer->width;
        $this->height = $gameLayer->height;
        $this->tiles  = [];

        $rawTiles = $gameLayer->getTiles();

        foreach ($rawTiles as $tile) {
            $index = $tile['index'];

            if ($index > 128) {
                $this->tiles[] = 0;
                continue;
            }

            $this->tiles[] = match ($index) {
                GameLayer::TILE_DEATH  => self::COLFLAG_DEATH,
                GameLayer::TILE_SOLID  => self::COLFLAG_SOLID,
                GameLayer::TILE_NOHOOK => self::COLFLAG_SOLID | self::COLFLAG_NOHOOK,
                default                => 0,
            };
        }
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    protected function getTile(int $x, int $y): int
    {
        $nx = max(0, min((int) ($x / 32), $this->width - 1));
        $ny = max(0, min((int) ($y / 32), $this->height - 1));

        $index = $this->tiles[$ny * $this->width + $nx] ?? 0;

        return $index > 128 ? 0 : $index;
    }

    protected function isTileSolid(int $x, int $y): bool
    {
        return ($this->getTile($x, $y) & self::COLFLAG_SOLID) !== 0;
    }

    public function checkPoint(float $x, float $y): bool
    {
        return $this->isTileSolid((int) round($x), (int) round($y));
    }

    public function getCollisionAt(float $x, float $y): int
    {
        return $this->getTile((int) round($x), (int) round($y));
    }

    /**
     * @return array{0: int, 1: Vector2, 2: Vector2}
     */
    public function intersectLine(Vector2 $pos0, Vector2 $pos1): array
    {
        $distance = $pos0->distance($pos1);
        if ($distance <= 0.0) {
            return [0, $pos1, $pos1];
        }

        $end  = (int) ($distance + 1);
        $last = $pos0;

        for ($i = 0; $i < $end; $i++) {
            $a   = $i / $distance;
            $pos = new Vector2(
                $pos0->x + ($pos1->x - $pos0->x) * $a,
                $pos0->y + ($pos1->y - $pos0->y) * $a,
            );

            if ($this->checkPoint($pos->x, $pos->y)) {
                return [$this->getCollisionAt($pos->x, $pos->y), $pos, $last];
            }

            $last = $pos;
        }

        return [0, $pos1, $pos1];
    }

    public function movePoint(Vector2 $pos, Vector2 $vel, float $elasticity, ?int &$bounces = null): void
    {
        $bounces = 0;

        if ($this->checkPoint($pos->x + $vel->x, $pos->y + $vel->y)) {
            $affected = 0;

            if ($this->checkPoint($pos->x + $vel->x, $pos->y)) {
                $vel->x *= -$elasticity;
                $bounces++;
                $affected++;
            }

            if ($this->checkPoint($pos->x, $pos->y + $vel->y)) {
                $vel->y *= -$elasticity;
                $bounces++;
                $affected++;
            }

            if ($affected === 0) {
                $vel->x *= -$elasticity;
                $vel->y *= -$elasticity;
            }
        } else {
            $pos->x += $vel->x;
            $pos->y += $vel->y;
        }
    }

    public function testBox(Vector2 $pos, Vector2 $size): bool
    {
        $halfW = $size->x * 0.5;
        $halfH = $size->y * 0.5;

        if ($this->checkPoint($pos->x - $halfW, $pos->y - $halfH)) {
            return true;
        }
        if ($this->checkPoint($pos->x + $halfW, $pos->y - $halfH)) {
            return true;
        }
        if ($this->checkPoint($pos->x - $halfW, $pos->y + $halfH)) {
            return true;
        }
        if ($this->checkPoint($pos->x + $halfW, $pos->y + $halfH)) {
            return true;
        }

        return false;
    }

    public function moveBox(Vector2 $pos, Vector2 $vel, Vector2 $size, float $elasticity): void
    {
        $distance = $vel->length();
        $max      = (int) $distance;

        if ($distance <= 0.00001) {
            return;
        }

        $fraction = 1.0 / ($max + 1);

        for ($i = 0; $i <= $max; $i++) {
            $newPos = new Vector2(
                $pos->x + $vel->x * $fraction,
                $pos->y + $vel->y * $fraction,
            );

            if ($this->testBox($newPos, $size)) {
                $hits = 0;

                if ($this->testBox(new Vector2($pos->x, $newPos->y), $size)) {
                    $newPos->y = $pos->y;
                    $vel->y *= -$elasticity;
                    $hits++;
                }

                if ($this->testBox(new Vector2($newPos->x, $pos->y), $size)) {
                    $newPos->x = $pos->x;
                    $vel->x *= -$elasticity;
                    $hits++;
                }

                // Corner case: neither test got a collision
                if ($hits === 0) {
                    $newPos->y = $pos->y;
                    $vel->y *= -$elasticity;
                    $newPos->x = $pos->x;
                    $vel->x *= -$elasticity;
                }
            }

            $pos->x = $newPos->x;
            $pos->y = $newPos->y;
        }
    }
}
