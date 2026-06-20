<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Collision;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

class CharacterEntity extends AbstractEntity
{
    public const PHYS_SIZE = 28;

    // Game state
    public int $health = 10;
    public int $armor = 0;
    public int $activeWeapon = 1; // WEAPON_GUN
    public bool $alive = false;
    public int $tick = 0;
    public int $attackTick = 0;
    public int $emote = 0;
    public int $playerFlags = 0;
    public int $reloadTimer = 0;

    // Physics state
    public Vector2 $vel;
    public Vector2 $hookPos;
    public Vector2 $hookDir;
    public int $hookTick = 0;
    public int $hookState = 0;
    public int $hookedPlayer = -1;
    public int $jumped = 0;
    public int $direction = 0;
    public int $angle = 0;
    public int $triggeredEvents = 0;

    // Tuning parameters (Teeworlds 0.6 defaults)
    public float $gravity              = 0.5;
    public float $groundControlSpeed   = 10.0;
    public float $groundControlAccel   = 2.0;
    public float $groundFriction       = 0.5;
    public float $groundJumpImpulse    = 13.2;
    public float $airJumpImpulse       = 12.0;
    public float $airControlSpeed      = 5.0;
    public float $airControlAccel      = 1.5;
    public float $airFriction          = 0.95;
    public float $hookLength           = 380.0;
    public float $hookFireSpeed        = 80.0;
    public float $hookDragAccel        = 3.0;
    public float $hookDragSpeed        = 15.0;
    public float $velrampStart         = 550.0;
    public float $velrampRange         = 2000.0;
    public float $velrampCurvature     = 1.4;
    public float $playerCollision      = 1.0;
    public float $playerHooking        = 1.0;

    public ?AbstractTee $tee = null;

    public function __construct(public Vector2 $position)
    {
        parent::__construct(position: $position);

        $this->vel     = new Vector2(0, 0);
        $this->hookPos = new Vector2(0, 0);
        $this->hookDir = new Vector2(0, 0);
    }

    public function getHitBoxRadius(): int
    {
        return self::PHYS_SIZE;
    }

    public function spawn(Vector2 $pos, ?AbstractTee $tee = null): void
    {
        $this->position   = $pos;
        $this->health     = 10;
        $this->armor      = 0;
        $this->alive      = true;
        $this->toDestroy  = false;
        $this->tee        = $tee;
        $this->tick       = 0;
        $this->direction  = 1;

        $this->vel           = new Vector2(0, 0);
        $this->hookPos       = new Vector2(0, 0);
        $this->hookDir       = new Vector2(0, 0);
        $this->hookTick      = 0;
        $this->hookState     = 0;
        $this->hookedPlayer  = -1;
        $this->jumped        = 0;
        $this->triggeredEvents = 0;
    }

    public function die(): void
    {
        $this->alive = false;
        $this->markToDestroy();
    }

    public function doTick(): void
    {
        if (! $this->alive || $this->world === null) {
            return;
        }

        $collision = $this->world->getMap()->getCollision();
        if ($collision === null) {
            return;
        }

        // Read input from the player's tee, or default to idle
        $direction = 0;
        $targetX   = 0;
        $targetY   = 0;
        $jump      = false;
        $hook      = false;

        if ($this->tee instanceof PlayerTee) {
            $direction = $this->tee->inputDirection;
            $targetX   = $this->tee->inputTargetX;
            $targetY   = $this->tee->inputTargetY;
            $jump      = $this->tee->inputJump;
            $hook      = $this->tee->inputHook;
        }

        $this->tickPhysics($direction, $targetX, $targetY, $jump, $hook, $collision);
        $this->move($collision);

        // Handle shooting
        if ($this->tee instanceof PlayerTee && $this->tee->inputFire && $this->reloadTimer <= 0) {
            if ($this->activeWeapon === 1) {
                $this->shootGun();
            }
            $this->reloadTimer = 6; // ~125ms at 50 tick/s
        }

        if ($this->reloadTimer > 0) {
            $this->reloadTimer--;
        }
    }

    // -- Physics --

    public function tickPhysics(int $inputDirection, int $inputTargetX, int $inputTargetY, bool $inputJump, bool $inputHook, Collision $collision): void
    {
        $physSize = 28.0;
        $this->triggeredEvents = 0;

        $grounded = false;
        if ($collision->checkPoint($this->position->x + $physSize / 2, $this->position->y + $physSize / 2 + 5)) {
            $grounded = true;
        }
        if ($collision->checkPoint($this->position->x - $physSize / 2, $this->position->y + $physSize / 2 + 5)) {
            $grounded = true;
        }

        $this->vel->y += $this->gravity;

        $maxSpeed  = $grounded ? $this->groundControlSpeed : $this->airControlSpeed;
        $accel     = $grounded ? $this->groundControlAccel : $this->airControlAccel;
        $friction  = $grounded ? $this->groundFriction : $this->airFriction;

        $this->direction = $inputDirection;

        // Setup angle
        $a = 0.0;
        if ($inputTargetX === 0) {
            $a = atan2((float) $inputTargetY, 0.01);
        } else {
            $a = atan2((float) $inputTargetY, (float) $inputTargetX);
        }
        if ($inputTargetX < 0) {
            $a += M_PI;
        }
        $this->angle = (int) ($a * 256.0);

        // Handle jump
        if ($inputJump) {
            if (! ($this->jumped & 1)) {
                if ($grounded) {
                    $this->triggeredEvents |= 0x01;
                    $this->vel->y = -$this->groundJumpImpulse;
                    $this->jumped |= 1;
                } elseif (! ($this->jumped & 2)) {
                    $this->triggeredEvents |= 0x02;
                    $this->vel->y = -$this->airJumpImpulse;
                    $this->jumped |= 3;
                }
            }
        } else {
            $this->jumped &= ~1;
        }

        // Handle hook
        if ($inputHook) {
            if ($this->hookState === 0) {
                $dirX = (float) $inputTargetX;
                $dirY = (float) $inputTargetY;
                $len = sqrt($dirX * $dirX + $dirY * $dirY);

                if ($len > 0) {
                    $dirX /= $len;
                    $dirY /= $len;

                    $this->hookState = 4;
                    $this->hookDir = new Vector2($dirX, $dirY);
                    $this->hookPos = new Vector2(
                        $this->position->x + $dirX * $physSize * 1.5,
                        $this->position->y + $dirY * $physSize * 1.5,
                    );
                    $this->hookedPlayer = -1;
                    $this->hookTick = 0;
                    $this->triggeredEvents |= 0x04;
                }
            }
        } else {
            $this->hookedPlayer = -1;
            $this->hookState = 0;
            $this->hookPos = $this->position;
        }

        // Accelerate in the wanted direction
        if ($this->direction < 0) {
            $this->vel->x = $this->saturatedAdd(-$maxSpeed, $maxSpeed, $this->vel->x, -$accel);
        }
        if ($this->direction > 0) {
            $this->vel->x = $this->saturatedAdd(-$maxSpeed, $maxSpeed, $this->vel->x, $accel);
        }
        if ($this->direction === 0) {
            $this->vel->x *= $friction;
        }

        if ($grounded) {
            $this->jumped &= ~2;
        }

        $this->tickHookStateMachine($collision);

        $speed = $this->vel->length();
        if ($speed > 6000) {
            $this->vel = $this->vel->normalize();
            $this->vel->x *= 6000;
            $this->vel->y *= 6000;
        }
    }

    private function tickHookStateMachine(Collision $collision): void
    {
        if ($this->hookState === 0) {
            $this->hookedPlayer = -1;
            $this->hookState = 0;
            $this->hookPos = $this->position;
        } elseif ($this->hookState >= 1 && $this->hookState < 3) {
            $this->hookState++;
        } elseif ($this->hookState === 3) {
            $this->hookState = -1;
            $this->triggeredEvents |= 0x40;
        } elseif ($this->hookState === 4) {
            $newHookPos = new Vector2(
                $this->hookPos->x + $this->hookDir->x * $this->hookFireSpeed,
                $this->hookPos->y + $this->hookDir->y * $this->hookFireSpeed,
            );

            if ($this->position->distance($newHookPos) > $this->hookLength) {
                $this->hookState = 1;
                $diff = $newHookPos->diff($this->position);
                $normalized = $diff->normalize();
                $newHookPos = new Vector2(
                    $this->position->x + $normalized->x * $this->hookLength,
                    $this->position->y + $normalized->y * $this->hookLength,
                );
            }

            [$hit] = $collision->intersectLine($this->hookPos, $newHookPos);
            if ($hit) {
                if ($hit & Collision::COLFLAG_NOHOOK) {
                    $this->triggeredEvents |= 0x20;
                    $this->hookState = 1;
                } else {
                    $this->triggeredEvents |= 0x10;
                    $this->hookState = 5;
                }
            }

            $this->hookPos = $newHookPos;
        }

        if ($this->hookState === 5) {
            if ($this->hookedPlayer === -1 && $this->hookPos->distance($this->position) > 46.0) {
                $hookVel = $this->hookPos->diff($this->position)->normalize();
                $hookVel->x *= $this->hookDragAccel;
                $hookVel->y *= $this->hookDragAccel;

                if ($hookVel->y > 0) {
                    $hookVel->y *= 0.3;
                }

                if (($hookVel->x < 0 && $this->direction < 0) || ($hookVel->x > 0 && $this->direction > 0)) {
                    $hookVel->x *= 0.95;
                } else {
                    $hookVel->x *= 0.75;
                }

                $newVel = new Vector2($this->vel->x + $hookVel->x, $this->vel->y + $hookVel->y);

                if ($newVel->length() < $this->hookDragSpeed || $newVel->length() < $this->vel->length()) {
                    $this->vel = $newVel;
                }
            }

            $this->hookTick++;
            if ($this->hookedPlayer !== -1 && $this->hookTick > 50 + 10) {
                $this->hookedPlayer = -1;
                $this->hookState = -1;
                $this->hookPos = $this->position;
            }
        }
    }

    public function move(Collision $collision): void
    {
        $speed = $this->vel->length();

        $rampValue = $this->velocityRamp($speed * 50, $this->velrampStart, $this->velrampRange, $this->velrampCurvature);

        $this->vel->x *= $rampValue;

        $newPos = new Vector2($this->position->x, $this->position->y);
        $collision->moveBox($newPos, $this->vel, new Vector2(28.0, 28.0), 0);

        $this->vel->x *= (1.0 / $rampValue);

        $this->position->x = $newPos->x;
        $this->position->y = $newPos->y;
    }

    private function saturatedAdd(float $min, float $max, float $current, float $modifier): float
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

    private function velocityRamp(float $value, float $start, float $range, float $curvature): float
    {
        if ($value < $start) {
            return 1.0;
        }
        return 1.0 / pow($curvature, ($value - $start) / $range);
    }

    // -- Shooting --

    private function shootGun(): void
    {
        if ($this->world === null) {
            return;
        }

        $angle = $this->angle / 256.0;
        $dir   = new Vector2(cos($angle), sin($angle));
        $speed = 2200.0;

        $proj = new ProjectileEntity(
            position: new Vector2($this->position->x, $this->position->y),
            direction: new Vector2($dir->x * $speed, $dir->y * $speed),
            type: 1,
        );

        $this->world->addEntity($proj);
    }

    // -- Snap --

    public function doSnap(AbstractTee $requestingTee): array
    {
        if (! $this->alive) {
            return [];
        }

        return [
            new ObjCharacterItem(
                tick: $this->tick,
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                velX: (int) round($this->vel->x * 256.0),
                velY: (int) round($this->vel->y * 256.0),
                angle: $this->angle,
                direction: $this->direction,
                jumped: $this->jumped,
                hookedPlayer: $this->hookedPlayer,
                hookState: $this->hookState,
                hookTick: $this->hookTick,
                hookX: (int) round($this->hookPos->x),
                hookY: (int) round($this->hookPos->y),
                hookDx: (int) round($this->hookDir->x * 256.0),
                hookDy: (int) round($this->hookDir->y * 256.0),
                playerFlags: $this->playerFlags,
                health: $this->health,
                armor: $this->armor,
                ammoCount: 10,
                weapon: $this->activeWeapon,
                emote: $this->emote,
                attackTick: $this->attackTick,
            ),
        ];
    }
}