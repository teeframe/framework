<?php

namespace TeeFrame\Game\Entities;

use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\GameConstants;
use TeeFrame\Map\Collision;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

abstract class AbstractCharacterEntity extends AbstractEntity
{
    public const PHYS_SIZE = 28;

    public const INPUT_STATE_MASK = 0x3F;

    // Game state
    public int $health = 10;
    public int $armor = 0;
    public int $activeWeapon = GameConstants::WEAPON_GUN;
    public int $lastWeapon = GameConstants::WEAPON_HAMMER;
    public int $queuedWeapon = -1;
    public bool $alive = false;
    public int $tick = 0;
    public int $attackTick = 0;
    public int $emote = 0;
    public int $playerFlags = 0;
    public int $reloadTimer = 0;

    /**
     * @var array<int, array{got: bool, ammo: int}>
     */
    public array $aWeapons = [];

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
    private bool $inputInitialized = false;

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

    // Ninja state
    public int $ninjaActivationTick = 0;
    public Vector2 $ninjaActivationDir;
    public int $ninjaCurrentMoveTime = 0;
    public float $ninjaOldVelAmount = 0.0;
    public int $ninjaNumObjectsHit = 0;

    /**
     * @var array<int, AbstractCharacterEntity|null>
     */
    public array $ninjaHitObjects = [];

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
        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects    = [];

        // Initialize weapons: hammer + gun
        $this->aWeapons = [];
        for ($i = 0; $i < GameConstants::NUM_WEAPONS; $i++) {
            $this->aWeapons[$i] = ['got' => false, 'ammo' => 0];
        }
        $this->aWeapons[GameConstants::WEAPON_HAMMER] = ['got' => true, 'ammo' => -1];
        $this->aWeapons[GameConstants::WEAPON_GUN]    = ['got' => true, 'ammo' => 10];

        $this->activeWeapon = GameConstants::WEAPON_GUN;
        $this->lastWeapon   = GameConstants::WEAPON_HAMMER;
        $this->queuedWeapon = -1;
        $this->reloadTimer  = 0;
        $this->attackTick   = 0;
        $this->inputInitialized = false;
    }

    public function die(int $killerTeeIndex = -1): void
    {
        $this->alive = false;
        $this->markToDestroy();

        // Player die sound
        $this->createSound(GameConstants::SOUND_PLAYER_DIE);

        // Notify game controller for scoring
        if ($this->world !== null) {
            $this->world->gameController()->onCharacterDeath($this, $killerTeeIndex);
        }

        // Set respawn on the tee
        if ($this->tee instanceof PlayerTee) {
            $respawnDelay = $killerTeeIndex === -1 ? 150 : 25; // 3s for self-kill, 0.5s normal
            $this->tee->respawnTick = $this->world !== null ? $this->world->getCurrentTick() + $respawnDelay : $respawnDelay;
            $this->tee->spawning = true;
        }
    }

    public function increaseHealth(int $amount): bool
    {
        if ($this->health >= 10) {
            return false;
        }
        $this->health = min(10, $this->health + $amount);
        return true;
    }

    public function increaseArmor(int $amount): bool
    {
        if ($this->armor >= 10) {
            return false;
        }
        $this->armor = min(10, $this->armor + $amount);
        return true;
    }

    public function giveNinja(): void
    {
        $this->ninjaActivationTick = $this->world !== null ? $this->world->getCurrentTick() : 0;
        $this->ninjaCurrentMoveTime = 0;
        $this->ninjaNumObjectsHit = 0;
        $this->ninjaHitObjects = [];

        $this->aWeapons[GameConstants::WEAPON_NINJA]['got']  = true;
        $this->aWeapons[GameConstants::WEAPON_NINJA]['ammo'] = -1;
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA) {
            $this->lastWeapon = $this->activeWeapon;
        }
        $this->activeWeapon = GameConstants::WEAPON_NINJA;
    }

    public function setEmote(int $emote, int $tick): void
    {
        $this->emote = $emote;
    }

    /**
     * Apply damage and knockback to this character.
     * Ported from Teeworlds 0.6 CCharacter::TakeDamage().
     */
    public function takeDamage(Vector2 $force, int $damage, AbstractCharacterEntity $inflictor): void
    {
        if (! $this->alive) {
            return;
        }

        $this->vel->x += $force->x;
        $this->vel->y += $force->y;

        // Armor absorption (ported from Teeworlds 0.6 CCharacter::TakeDamage)
        if ($damage > 0 && $this->armor > 0) {
            if ($damage > 1) {
                $this->health--;
                $damage--;
            }

            if ($damage > $this->armor) {
                $damage -= $this->armor;
                $this->armor = 0;
            } else {
                $this->armor -= $damage;
                $damage = 0;
            }
        }

        $this->health -= $damage;

        // Player pain sound
        if ($this->world !== null && $damage > 0) {
            $this->createSound($damage > 2 ? GameConstants::SOUND_PLAYER_PAIN_LONG : GameConstants::SOUND_PLAYER_PAIN_SHORT);
        }

        if ($this->health <= 0) {
            $killerTeeIndex = $inflictor->tee !== null ? $inflictor->tee->teeIndex : -1;
            $this->die($killerTeeIndex);

            // Add death event
            if ($this->world !== null) {
                $teeIndex = $this->tee instanceof AbstractTee ? $this->tee->teeIndex : -1;
                $this->world->addEvent(new \TeeFrame\Network\SnapItems\ObjEventDeathItem(
                    x: (int) round($this->position->x),
                    y: (int) round($this->position->y),
                    clientId: $teeIndex,
                ));
            }
        }
    }

    public function doTick(): void
    {
        if (! $this->alive || $this->world === null) {
            return;
        }

        $this->tick = $this->world->getCurrentTick();

        $collision = $this->world->getMap()->getCollision();
        if ($collision === null) {
            return;
        }

        // Update tee's viewPosition to follow the character.
        // The viewPosition is used by doEntitySnap for distance culling.
        if ($this->tee !== null) {
            $this->tee->viewPosition = new Vector2(
                (int) round($this->position->x),
                (int) round($this->position->y),
            );
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

            // Handle weapon switch from input
            $this->handleWeaponSwitch();
        }

        $this->tickPhysics($direction, $targetX, $targetY, $jump, $hook, $collision);
        $this->move($collision);

        // Handle shooting
        // m_Fire is an incrementing counter (like m_NextWeapon/m_PrevWeapon).
        // Use CountInput to detect presses.
        $firePressed = false;
        if ($this->tee instanceof PlayerTee) {
            // On the first tick, sync prev inputs to current to avoid
            // spurious presses from the client's pre-existing counter state.
            if (! $this->inputInitialized) {
                $this->tee->prevInputFire         = $this->tee->inputFire;
                $this->tee->prevInputWantedWeapon = $this->tee->inputWantedWeapon;
                $this->tee->prevInputNextWeapon   = $this->tee->inputNextWeapon;
                $this->tee->prevInputPrevWeapon   = $this->tee->inputPrevWeapon;
                $this->inputInitialized = true;
            }

            $firePresses = $this->countInput($this->tee->prevInputFire, $this->tee->inputFire);
            $firePressed = $firePresses > 0 && $firePresses < 128;
        }

        $this->handleWeapons($firePressed);

        if ($this->reloadTimer > 0) {
            $this->reloadTimer--;
        }

        // Save previous input for next tick's CountInput
        if ($this->tee instanceof PlayerTee) {
            $this->tee->prevInputWantedWeapon = $this->tee->inputWantedWeapon;
            $this->tee->prevInputNextWeapon   = $this->tee->inputNextWeapon;
            $this->tee->prevInputPrevWeapon   = $this->tee->inputPrevWeapon;
            $this->tee->prevInputFire         = $this->tee->inputFire;
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

        // Setup angle (original Teeworlds 0.6: atan(y/x) + pi if x < 0)
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

        // Handle player <-> player collision and hook influence
        if ($this->world !== null) {
            foreach ($this->world->getEntities() as $entity) {
                if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                    continue;
                }

                $distance = $this->position->distance($entity->position);
                $dir = $this->position->diff($entity->position);
                $dirLen = $dir->length();
                if ($dirLen > 0.0) {
                    $dir = $dir->normalize();
                } else {
                    $dir = new Vector2(1, 0);
                }

                // Player collision
                if ($this->playerCollision > 0 && $distance < $physSize * 1.25 && $distance > 0.0) {
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
                if ($entity->tee !== null && $this->hookedPlayer === $entity->tee->teeIndex && $this->playerHooking > 0) {
                    if ($distance > $physSize * 1.50) {
                        $hookAccel = $this->hookDragAccel * ($distance / $this->hookLength);
                        $hookDragSpeed = $this->hookDragSpeed;

                        // add force to the hooked player
                        $entity->vel->x = $this->saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $entity->vel->x, $hookAccel * $dir->x * 1.5);
                        $entity->vel->y = $this->saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $entity->vel->y, $hookAccel * $dir->y * 1.5);

                        // add a little bit force to the guy who has the grip
                        $this->vel->x = $this->saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $this->vel->x, -$hookAccel * $dir->x * 0.25);
                        $this->vel->y = $this->saturatedAdd(-$hookDragSpeed, $hookDragSpeed, $this->vel->y, -$hookAccel * $dir->y * 0.25);
                    }
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
            if ($this->world !== null && $this->playerHooking > 0) {
                $closestDist = PHP_FLOAT_MAX;

                foreach ($this->world->getEntities() as $entity) {
                    if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                        continue;
                    }

                    $closestPoint = $entity->position->closestPointOnLine($this->hookPos, $newHookPos);
                    $dist = $entity->position->distance($closestPoint);

                    if ($dist < self::PHYS_SIZE + 2.0) {
                        $lineDist = $this->hookPos->distance($closestPoint);
                        if ($lineDist < $closestDist) {
                            $closestDist = $lineDist;
                            $this->triggeredEvents |= 0x08; // COREEVENT_HOOK_ATTACH_PLAYER
                            $this->hookState = 5; // HOOK_GRABBED
                            $this->hookedPlayer = $entity->tee !== null ? $entity->tee->teeIndex : -1;
                        }
                    }
                }
            }

            if ($this->hookState === 4) {
                if ($goingToHitGround) {
                    $this->triggeredEvents |= 0x10;
                    $this->hookState = 5;
                } elseif ($goingToRetract) {
                    $this->triggeredEvents |= 0x20;
                    $this->hookState = 1;
                }
            }

            $this->hookPos = $newHookPos;
        }

        if ($this->hookState === 5) {
            // Follow hooked player's position
            if ($this->hookedPlayer !== -1 && $this->world !== null) {
                $hookedChar = null;
                foreach ($this->world->getEntities() as $entity) {
                    if ($entity instanceof AbstractCharacterEntity && $entity->tee !== null && $entity->tee->teeIndex === $this->hookedPlayer) {
                        $hookedChar = $entity;
                        break;
                    }
                }

                if ($hookedChar !== null) {
                    $this->hookPos = clone $hookedChar->position;
                } else {
                    // Hooked player left — release hook
                    $this->hookedPlayer = -1;
                    $this->hookState = -1;
                    $this->hookPos = $this->position;
                }
            }

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

    /**
     * Handle weapon firing. Override in game-mode subclasses for custom weapon logic.
     * Default: no-op (framework is game-mode agnostic).
     */
    protected function handleWeapons(bool $firePressed): void
    {
    }

    abstract protected function shootHammer(): int;

    abstract protected function shootGun(): int;

    // -- Weapon Switch --

    /**
     * Count how many press/release transitions happened between two input states.
     * Ported from Teeworlds 0.6 CInputCount CountInput().
     */
    private function countInput(int $prev, int $cur): int
    {
        $prev &= self::INPUT_STATE_MASK;
        $cur  &= self::INPUT_STATE_MASK;
        $presses = 0;
        $i = $prev;

        while ($i !== $cur) {
            $i = ($i + 1) & self::INPUT_STATE_MASK;
            if ($i & 1) {
                $presses++;
            }
        }

        return $presses;
    }

    /**
     * Process weapon switch input and queue the desired weapon.
     * Ported from Teeworlds 0.6 CCharacter::HandleWeaponSwitch().
     */
    private function handleWeaponSwitch(): void
    {
        if (! $this->tee instanceof PlayerTee) {
            return;
        }

        $wantedWeapon = $this->activeWeapon;
        if ($this->queuedWeapon !== -1) {
            $wantedWeapon = $this->queuedWeapon;
        }

        // Next / Prev weapon selection
        $next = $this->countInput($this->tee->prevInputNextWeapon, $this->tee->inputNextWeapon);
        $prev = $this->countInput($this->tee->prevInputPrevWeapon, $this->tee->inputPrevWeapon);

        if ($next < 128) {
            while ($next > 0) {
                $wantedWeapon = ($wantedWeapon + 1) % GameConstants::NUM_WEAPONS;
                if ($this->aWeapons[$wantedWeapon]['got']) {
                    $next--;
                }
            }
        }

        if ($prev < 128) {
            while ($prev > 0) {
                $wantedWeapon = ($wantedWeapon - 1) < 0 ? GameConstants::NUM_WEAPONS - 1 : $wantedWeapon - 1;
                if ($this->aWeapons[$wantedWeapon]['got']) {
                    $prev--;
                }
            }
        }

        // Direct weapon selection (1-indexed from client, convert to 0-indexed)
        if ($this->tee->inputWantedWeapon > 0) {
            $wantedWeapon = $this->tee->inputWantedWeapon - 1;
            $this->tee->inputWantedWeapon = 0; // clear after processing
        }

        // Queue the switch if valid
        if ($wantedWeapon >= 0 && $wantedWeapon < GameConstants::NUM_WEAPONS
            && $wantedWeapon !== $this->activeWeapon
            && $this->aWeapons[$wantedWeapon]['got']) {
            $this->queuedWeapon = $wantedWeapon;
        }

        $this->doWeaponSwitch();
    }

    /**
     * Execute the queued weapon switch if conditions allow.
     * Ported from Teeworlds 0.6 CCharacter::DoWeaponSwitch().
     */
    protected function doWeaponSwitch(): void
    {
        // Can't switch while reloading, no weapon queued, or holding ninja
        if ($this->reloadTimer !== 0 || $this->queuedWeapon === -1 || $this->aWeapons[GameConstants::WEAPON_NINJA]['got']) {
            return;
        }

        $this->setWeapon($this->queuedWeapon);
    }

    /**
     * Perform the actual weapon switch.
     * Ported from Teeworlds 0.6 CCharacter::SetWeapon().
     */
    protected function setWeapon(int $weapon): void
    {
        if ($weapon === $this->activeWeapon) {
            return;
        }

        $this->lastWeapon   = $this->activeWeapon;
        $this->queuedWeapon = -1;
        $this->activeWeapon = $weapon;

        $this->createSound(GameConstants::SOUND_WEAPON_SWITCH);

        if ($this->activeWeapon < 0 || $this->activeWeapon >= GameConstants::NUM_WEAPONS) {
            $this->activeWeapon = 0;
        }
    }

    /**
     * Give a weapon to the character with the specified ammo.
     * Ported from Teeworlds 0.6 CCharacter::GiveWeapon().
     */
    public function giveWeapon(int $weapon, int $ammo): bool
    {
        if ($weapon < 0 || $weapon >= GameConstants::NUM_WEAPONS) {
            return false;
        }

        if ($this->aWeapons[$weapon]['ammo'] < 10 || ! $this->aWeapons[$weapon]['got']) {
            $this->aWeapons[$weapon]['got']  = true;
            $this->aWeapons[$weapon]['ammo'] = min(10, $ammo);
            return true;
        }

        return false;
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
                ammoCount: $this->aWeapons[$this->activeWeapon]['ammo'],
                weapon: $this->activeWeapon,
                emote: $this->emote,
                attackTick: $this->attackTick,
            ),
        ];
    }

    private function createSound(int $soundId): void
    {
        if ($this->world === null) {
            return;
        }

        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: $soundId,
        ));
    }
}