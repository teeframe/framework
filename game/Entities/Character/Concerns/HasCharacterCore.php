<?php

namespace TeeFrame\Game\Entities\Character\Concerns;

use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\World\TuneController;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;

/**
 * Character physics core — pure physics, no game logic.
 *
 * Handles: velocity, hook state machine, movement, player-player collision,
 * and hook influence. Does NOT handle weapons, health, spawning, or any
 * game-mode-specific logic.
 */
trait HasCharacterCore
{
    public Vector2 $vel;
    public Vector2 $hookPos;
    public Vector2 $hookDir;
    public int $hookTick = 0;
    public int $hookState = GameConstants::HOOK_IDLE;
    public int $hookedPlayer = -1;
    public int $jumped = 0;
    public int $direction = 0;
    public int $angle = 0;
    public int $triggeredEvents = 0;

    protected function initCore(Vector2 $position): void
    {
        $this->position = clone $position;
        $this->vel      = new Vector2(0, 0);
        $this->hookPos  = new Vector2(0, 0);
        $this->hookDir  = new Vector2(0, 0);
        $this->hookTick       = 0;
        $this->hookState      = GameConstants::HOOK_IDLE;
        $this->hookedPlayer   = -1;
        $this->jumped         = 0;
        $this->direction      = 0;
        $this->angle          = 0;
        $this->triggeredEvents = 0;
    }

    /**
     * @param array<int, AbstractCharacterEntity> $otherCharacters
     */
    public function tick(
        int $inputDirection,
        int $inputTargetX,
        int $inputTargetY,
        bool $inputJump,
        bool $inputHook,
        Collision $collision,
        TuneController $tune,
        array $otherCharacters,
    ): void {
        $physSize = 28.0;
        $this->triggeredEvents = 0;

        $grounded = false;
        if ($collision->checkPoint($this->position->x + $physSize / 2, $this->position->y + $physSize / 2 + 5)) {
            $grounded = true;
        }
        if ($collision->checkPoint($this->position->x - $physSize / 2, $this->position->y + $physSize / 2 + 5)) {
            $grounded = true;
        }

        $this->vel->y += $tune->gravity / 100.0;

        $maxSpeed  = $grounded ? $tune->groundControlSpeed / 100.0 : $tune->airControlSpeed / 100.0;
        $accel     = $grounded ? $tune->groundControlAccel / 100.0 : $tune->airControlAccel / 100.0;
        $friction  = $grounded ? $tune->groundFriction / 100.0 : $tune->airFriction / 100.0;

        $this->direction = $inputDirection;

        // Setup angle: atan(y/x) + pi if x < 0
        $a = 0.0;
        if ($inputTargetX === 0) {
            $a = atan((float) $inputTargetY);
        } else {
            $a = atan((float) $inputTargetY / (float) $inputTargetX);
        }
        if ($inputTargetX < 0) {
            $a += M_PI;
        }
        $this->angle = (int) ($a * 256.0);

        // Handle jump
        if ($inputJump) {
            if (! ($this->jumped & 1)) {
                if ($grounded) {
                    $this->triggeredEvents |= GameConstants::COREEVENT_GROUND_JUMP;
                    $this->vel->y = -$tune->groundJumpImpulse / 100.0;
                    $this->jumped |= 1;
                } elseif (! ($this->jumped & 2)) {
                    $this->triggeredEvents |= GameConstants::COREEVENT_AIR_JUMP;
                    $this->vel->y = -$tune->airJumpImpulse / 100.0;
                    $this->jumped |= 3;
                }
            }
        } else {
            $this->jumped &= ~1;
        }

        // Handle hook
        if ($inputHook) {
            if ($this->hookState === GameConstants::HOOK_IDLE) {
                $dirX = (float) $inputTargetX;
                $dirY = (float) $inputTargetY;
                $len = sqrt($dirX * $dirX + $dirY * $dirY);

                if ($len > 0) {
                    $dirX /= $len;
                    $dirY /= $len;

                    $this->hookState = GameConstants::HOOK_FLYING;
                    $this->hookDir = new Vector2($dirX, $dirY);
                    $this->hookPos = new Vector2(
                        $this->position->x + $dirX * $physSize * 1.5,
                        $this->position->y + $dirY * $physSize * 1.5,
                    );
                    $this->hookedPlayer = -1;
                    $this->hookTick = 0;
                    $this->triggeredEvents |= GameConstants::COREEVENT_HOOK_LAUNCH;
                }
            }
        } else {
            $this->hookedPlayer = -1;
            $this->hookState = GameConstants::HOOK_IDLE;
            $this->hookPos = $this->position;
        }

        // Accelerate in the wanted direction
        if ($this->direction < 0) {
            $this->vel->x = self::saturatedAdd(-$maxSpeed, $maxSpeed, $this->vel->x, -$accel);
        }
        if ($this->direction > 0) {
            $this->vel->x = self::saturatedAdd(-$maxSpeed, $maxSpeed, $this->vel->x, $accel);
        }
        if ($this->direction === 0) {
            $this->vel->x *= $friction;
        }

        if ($grounded) {
            $this->jumped &= ~2;
        }

        $this->tickHookStateMachine($collision, $tune, $otherCharacters);

        // Handle player <-> player collision and hook influence
        foreach ($otherCharacters as $teeIndex => $otherCharacter) {
            $distance = $this->position->distance($otherCharacter->position);
            $dir = $this->position->diff($otherCharacter->position);
            $dirLen = $dir->length();
            if ($dirLen > 0.0) {
                $dir = $dir->normalize();
            } else {
                $dir = new Vector2(1, 0);
            }

            // Player collision
            if ($tune->playerCollision / 100.0 > 0 && $distance < $physSize * 1.25 && $distance > 0.0) {
                $a = ($physSize * 1.45 - $distance);
                $velocity = 0.5;

                if ($this->vel->length() > 0.0001) {
                    $velocity = 1 - ($this->vel->normalize()->dot($dir) + 1) / 2;
                }

                $this->vel->x += $dir->x * $a * ($velocity * 0.75);
                $this->vel->y += $dir->y * $a * ($velocity * 0.75);
                $this->vel->x *= 0.85;
                $this->vel->y *= 0.85;
            }

            // Handle hook influence
            if ($this->hookedPlayer === $teeIndex && $tune->playerHooking / 100.0 > 0) {
                if ($distance > $physSize * 1.50) {
                    $hookAccel = ($tune->hookDragAccel / 100.0) * ($distance / ($tune->hookLength / 100.0));
                    $hookDragSpeed = $tune->hookDragSpeed / 100.0;

                    // add force to the hooked player
                    $otherCharacter->vel->x = self::saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $otherCharacter->vel->x, $hookAccel * $dir->x * 1.5);
                    $otherCharacter->vel->y = self::saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $otherCharacter->vel->y, $hookAccel * $dir->y * 1.5);

                    // add a little bit force to the guy who has the grip
                    $this->vel->x = self::saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $this->vel->x, -$hookAccel * $dir->x * 0.25);
                    $this->vel->y = self::saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $this->vel->y, -$hookAccel * $dir->y * 0.25);
                }
            }
        }

        $speed = $this->vel->length();
        if ($speed > 6000) {
            $this->vel = $this->vel->normalize();
            $this->vel->x *= 6000;
            $this->vel->y *= 6000;
        }
    }

    public function move(Collision $collision, TuneController $tune): void
    {
        $speed = $this->vel->length();

        $rampValue = self::velocityRamp($speed * 50, $tune->velrampStart / 100.0, $tune->velrampRange / 100.0, $tune->velrampCurvature / 100.0);

        $this->vel->x *= $rampValue;

        $newPos = new Vector2($this->position->x, $this->position->y);
        $collision->moveBox($newPos, $this->vel, new Vector2(28.0, 28.0), 0);

        $this->vel->x *= (1.0 / $rampValue);

        $this->position->x = $newPos->x;
        $this->position->y = $newPos->y;
    }

    /**
     * @param array<int, AbstractCharacterEntity> $otherCharacters
     */
    protected function tickHookStateMachine(Collision $collision, TuneController $tune, array $otherCharacters): void
    {
        if ($this->hookState === GameConstants::HOOK_IDLE) {
            $this->hookedPlayer = -1;
            $this->hookState = GameConstants::HOOK_IDLE;
            $this->hookPos = $this->position;
        } elseif ($this->hookState >= GameConstants::HOOK_RETRACT_START && $this->hookState < GameConstants::HOOK_RETRACT_END) {
            $this->hookState++;
        } elseif ($this->hookState === GameConstants::HOOK_RETRACT_END) {
            $this->hookState = GameConstants::HOOK_RETRACTED;
            $this->triggeredEvents |= GameConstants::COREEVENT_HOOK_RETRACT;
        } elseif ($this->hookState === GameConstants::HOOK_FLYING) {
            $newHookPos = new Vector2(
                $this->hookPos->x + $this->hookDir->x * ($tune->hookFireSpeed / 100.0),
                $this->hookPos->y + $this->hookDir->y * ($tune->hookFireSpeed / 100.0),
            );

            if ($this->position->distance($newHookPos) > ($tune->hookLength / 100.0)) {
                $this->hookState = GameConstants::HOOK_RETRACT_START;
                $diff = $newHookPos->diff($this->position);
                $normalized = $diff->normalize();
                $newHookPos = new Vector2(
                    $this->position->x + $normalized->x * ($tune->hookLength / 100.0),
                    $this->position->y + $normalized->y * ($tune->hookLength / 100.0),
                );
            }

            [$hit, $colPos] = $collision->intersectLine($this->hookPos, $newHookPos);
            $goingToHitGround = false;
            $goingToRetract   = false;

            if ($hit) {
                if ($hit & Collision::COLFLAG_NOHOOK) {
                    $goingToRetract = true;
                } else {
                    $goingToHitGround = true;
                }

                $newHookPos = $colPos;
            }

            // Check against other players
            if ($tune->playerHooking / 100.0 > 0) {
                $closestDist = PHP_FLOAT_MAX;

                foreach ($otherCharacters as $teeIdx => $otherCharacter) {
                    $closestPoint = $otherCharacter->position->closestPointOnLine($this->hookPos, $newHookPos);
                    $dist = $otherCharacter->position->distance($closestPoint);

                    if ($dist < 28.0 + 2.0) {
                        $lineDist = $this->hookPos->distance($closestPoint);
                        if ($lineDist < $closestDist) {
                            $closestDist = $lineDist;
                            $this->triggeredEvents |= GameConstants::COREEVENT_HOOK_ATTACH_PLAYER;
                            $this->hookState = GameConstants::HOOK_GRABBED;
                            $this->hookedPlayer = $teeIdx;
                        }
                    }
                }
            }

            if ($this->hookState === GameConstants::HOOK_FLYING) {
                if ($goingToHitGround) {
                    $this->triggeredEvents |= GameConstants::COREEVENT_HOOK_ATTACH_GROUND;
                    $this->hookState = GameConstants::HOOK_GRABBED;
                } elseif ($goingToRetract) {
                    $this->triggeredEvents |= GameConstants::COREEVENT_HOOK_HIT_NOHOOK;
                    $this->hookState = GameConstants::HOOK_RETRACT_START;
                }
            }

            $this->hookPos = $newHookPos;
        }

        if ($this->hookState === GameConstants::HOOK_GRABBED) {
            // Follow hooked player's position
            if ($this->hookedPlayer >= 0) {
                if (isset($otherCharacters[$this->hookedPlayer])) {
                    $this->hookPos = clone $otherCharacters[$this->hookedPlayer]->position;
                } else {
                    // Hooked player left — release hook
                    $this->hookedPlayer = -1;
                    $this->hookState = GameConstants::HOOK_RETRACTED;
                    $this->hookPos = $this->position;
                }
            }

            if ($this->hookedPlayer === -1 && $this->hookPos->distance($this->position) > 46.0) {
                $hookVel = $this->hookPos->diff($this->position)->normalize();
                $hookVel->x *= $tune->hookDragAccel / 100.0;
                $hookVel->y *= $tune->hookDragAccel / 100.0;

                if ($hookVel->y > 0) {
                    $hookVel->y *= 0.3;
                }

                if (($hookVel->x < 0 && $this->direction < 0) || ($hookVel->x > 0 && $this->direction > 0)) {
                    $hookVel->x *= 0.95;
                } else {
                    $hookVel->x *= 0.75;
                }

                $newVel = new Vector2($this->vel->x + $hookVel->x, $this->vel->y + $hookVel->y);

                if ($newVel->length() < ($tune->hookDragSpeed / 100.0) || $newVel->length() < $this->vel->length()) {
                    $this->vel = $newVel;
                }
            }

            $this->hookTick++;
            if ($this->hookedPlayer >= 0 && $this->hookTick > 50 + 10) {
                $this->hookedPlayer = -1;
                $this->hookState = GameConstants::HOOK_RETRACTED;
                $this->hookPos = $this->position;
            }
        }
    }

    public static function saturatedAdd(float $min, float $max, float $current, float $modifier): float
    {
        if ($modifier < 0) {
            if ($current < $min) {
                return $current;
            }
            $current += $modifier;
            if ($current < $min) {
                $current = $min;
            }
            return $current;
        }

        if ($current > $max) {
            return $current;
        }
        $current += $modifier;
        if ($current > $max) {
            $current = $max;
        }
        return $current;
    }

    public static function velocityRamp(float $value, float $start, float $range, float $curvature): float
    {
        if ($value < $start) {
            return 1.0;
        }
        return 1.0 / pow($curvature, ($value - $start) / $range);
    }
}
