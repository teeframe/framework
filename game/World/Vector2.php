<?php

namespace TeeFrame\Game\World;

class Vector2
{
    public function __construct(public float $x, public float $y)
    {
    }

    public function add(Vector2 $other): Vector2
    {
        return new Vector2($this->x + $other->x, $this->y + $other->y);
    }

    public function mul(float $scalar): Vector2
    {
        return new Vector2($this->x * $scalar, $this->y * $scalar);
    }

    public function diff(Vector2 $other): Vector2
    {
        return new Vector2($this->x - $other->x, $this->y - $other->y);
    }

    public function length(): float
    {
        return sqrt($this->x ** 2 + $this->y ** 2);
    }

    public function normalize(): Vector2
    {
        $length = $this->length();

        return new Vector2($this->x / $length, $this->y / $length);
    }

    public function distance(Vector2 $other): float
    {
        return $this->diff($other)->length();
    }

    public function nextPosition(Vector2 $velocity, float $curvature, float $speed, float $time): Vector2
    {
        $speedTime = $speed * $time;

        return new Vector2(
            x: $this->x + $velocity->x * $speedTime,
            y: $this->y + $velocity->y * $speedTime + ($curvature / 10000 * ($time ** 2))
        );
    }

    public function closestPointOnLine(Vector2 $lineStart, Vector2 $lineEnd): Vector2
    {
        $line       = $lineEnd->diff($lineStart);
        $lineLength = $line->length();

        if ($lineLength < 0.0001) {
            return clone $lineStart;
        }

        $lineNormalized = $line->normalize();

        $point = $this->diff($lineStart);
        $dot   = max(0, min($lineLength, $point->x * $lineNormalized->x + $point->y * $lineNormalized->y));

        return new Vector2($lineStart->x + $lineNormalized->x * $dot, $lineStart->y + $lineNormalized->y * $dot);
    }
}
