<?php

namespace TeeFrame\Game\Entities\Character;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Entities\Character\Concerns\HasCharacterCore;
use TeeFrame\Game\Entities\Character\Concerns\HasCharacterLifecycle;
use TeeFrame\Game\Entities\Character\Concerns\HasWeaponSwitching;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

abstract class AbstractCharacterEntity extends AbstractEntity
{
    use HasCharacterCore;
    use HasCharacterLifecycle;
    use HasWeaponSwitching;

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

    protected bool $inputInitialized = false;

    public function __construct(AbstractWorld $world, protected Vector2 $position)
    {
        parent::__construct(world: $world, position: $position);

        $this->initCore($position);
    }

    abstract protected function handleWeapons(bool $firePressed): void;

    public function getHitBoxRadius(): int
    {
        return self::PHYS_SIZE;
    }

    public function doTick(): void
    {
        if (! $this->alive) {
            return;
        }

        $this->tick = $this->world->getCurrentTick();

        $collision = $this->world->getMap()->getCollision();
        if ($collision === null) {
            return;
        }

        // Update tee's viewPosition to follow the character.
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

        // Build other characters array keyed by tee index
        $otherCharacters = [];
        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                continue;
            }
            if ($entity->tee !== null) {
                $otherCharacters[$entity->tee->teeIndex] = $entity;
            }
        }

        $tune = $this->world->getTuneController();
        $this->tick($direction, $targetX, $targetY, $jump, $hook, $collision, $tune, $otherCharacters);
        $this->move($collision, $tune);

        // Handle shooting
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
}
