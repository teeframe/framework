<?php

namespace TeeFrame\Game\Entities\Character;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\AbstractEntity;
use TeeFrame\Game\Entities\Character\Concerns\HasCharacterCore;
use TeeFrame\Game\Entities\Character\Concerns\HasCharacterLifecycle;
use TeeFrame\Game\Entities\Character\Concerns\HasWeaponSwitching;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

abstract class AbstractCharacterEntity extends AbstractEntity
{
    use HasCharacterCore;
    use HasCharacterLifecycle;
    use HasWeaponSwitching;

    public const PHYS_SIZE        = 28;
    public const INPUT_STATE_MASK = GameConstants::INPUT_STATE_MASK;

    // Game state
    public int $health = 10;

    public int $armor = 0;

    public int $activeWeapon = GameConstants::WEAPON_GUN;

    public int $lastWeapon = GameConstants::WEAPON_HAMMER;

    public int $queuedWeapon = -1;

    public bool $alive = false;

    public int $tick = 0;

    public int $attackTick = 0;

    public int $emote = GameConstants::EMOTE_NORMAL;

    public int $playerFlags = 0;

    public int $reloadTimer = 0;

    /**
     * @var array<int, CharacterWeaponState>
     */
    public array $weapons = [];

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

    // Input state (mirrors CCharacter::m_Input / m_LatestInput / m_LatestPrevInput)
    protected PlayerInput $input;

    protected PlayerInput $latestInput;

    protected PlayerInput $latestPrevInput;

    protected int $numInputs = 0;

    protected int $lastActionTick = 0;

    public function __construct(AbstractWorld $world, protected Vector2 $position)
    {
        parent::__construct(world: $world, position: $position);

        $this->initCore($position);

        $this->input           = $this->makeIdleInput();
        $this->latestInput     = $this->makeIdleInput();
        $this->latestPrevInput = $this->makeIdleInput();
    }

    /**
     * Apply a buffered input for the given prediction tick (CCharacter::OnPredictedInput).
     */
    public function onPredictedInput(PlayerInput $newInput): void
    {
        // Check for changes (mem_comp)
        if ($this->input != $newInput) {
            $this->lastActionTick = $this->world->getCurrentTick();
        }

        // Copy new input
        $this->input = $newInput;
        $this->numInputs++;

        // It is not allowed to aim in the center
        if ($this->input->targetX === 0 && $this->input->targetY === 0) {
            $this->input = new PlayerInput(
                direction: $newInput->direction,
                targetX: 0,
                targetY: -1,
                jump: $newInput->jump,
                fire: $newInput->fire,
                hook: $newInput->hook,
                playerFlags: $newInput->playerFlags,
                wantedWeapon: $newInput->wantedWeapon,
                nextWeapon: $newInput->nextWeapon,
                prevWeapon: $newInput->prevWeapon,
            );
        }
    }

    /**
     * Apply the buffered input for the current tick to latestInput (CCharacter::OnDirectInput subset).
     * Called by AbstractWorld::doTick() before ticking entities.
     */
    public function applyInput(): void
    {
        $this->latestPrevInput = $this->latestInput;
        $this->latestInput     = $this->input;
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

        // Handle weapon switch from latest input (only after enough inputs received)
        if ($this->tee instanceof PlayerTee && $this->numInputs > 2) {
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
        $this->tick(
            $this->input->direction,
            $this->input->targetX,
            $this->input->targetY,
            $this->input->jump,
            $this->input->hook,
            $collision,
            $tune,
            $otherCharacters,
        );
        $radius = self::PHYS_SIZE / 3;
        if (($collision->getCollisionAt($this->position->x + $radius, $this->position->y - $radius) & Collision::COLFLAG_DEATH)
            || ($collision->getCollisionAt($this->position->x + $radius, $this->position->y + $radius) & Collision::COLFLAG_DEATH)
            || ($collision->getCollisionAt($this->position->x - $radius, $this->position->y - $radius) & Collision::COLFLAG_DEATH)
            || ($collision->getCollisionAt($this->position->x - $radius, $this->position->y + $radius) & Collision::COLFLAG_DEATH)
            || $this->gameLayerClipped($this->position)
        ) {
            $this->die($this->tee !== null ? $this->tee->teeIndex : -1, GameConstants::WEAPON_WORLD);

            return;
        }

        $this->move($collision, $tune);

        // The local client predicts ground-jump and ground/no-hook sounds, but
        // other clients need server events (CCharacter::TickDefered in 0.6).
        $events = $this->triggeredEvents;
        $excludedTeeIndex = $this->tee?->teeIndex;
        if ($events & GameConstants::COREEVENT_GROUND_JUMP) {
            $this->createSound(GameConstants::SOUND_PLAYER_JUMP, $excludedTeeIndex);
        }
        if ($events & GameConstants::COREEVENT_HOOK_ATTACH_PLAYER) {
            $this->createSound(GameConstants::SOUND_HOOK_ATTACH_PLAYER);
        }
        if ($events & GameConstants::COREEVENT_HOOK_ATTACH_GROUND) {
            $this->createSound(GameConstants::SOUND_HOOK_ATTACH_GROUND, $excludedTeeIndex);
        }
        if ($events & GameConstants::COREEVENT_HOOK_HIT_NOHOOK) {
            $this->createSound(GameConstants::SOUND_HOOK_NOATTACH, $excludedTeeIndex);
        }

        // Handle shooting (only after enough inputs received)
        $firePressed = false;
        if ($this->tee instanceof PlayerTee && $this->numInputs > 2) {
            $firePresses = $this->countInput($this->latestPrevInput->fire, $this->latestInput->fire);
            $firePressed = $firePresses > 0 && $firePresses < 128;

            // Full-auto weapons (grenade, shotgun, rifle) keep firing while the
            // fire button is held and there's ammo (mirrors CCharacter::FireWeapon
            // in teeworlds 0.6).
            if (! $firePressed
                && ($this->activeWeapon    === GameConstants::WEAPON_GRENADE
                    || $this->activeWeapon === GameConstants::WEAPON_SHOTGUN
                    || $this->activeWeapon === GameConstants::WEAPON_RIFLE)
                && ($this->latestInput->fire & 1)
                && $this->weapons[$this->activeWeapon]->ammo
            ) {
                $firePressed = true;
            }
        }

        $this->handleWeapons($firePressed);

        if ($this->reloadTimer > 0) {
            $this->reloadTimer--;
        }
    }

    public function tickPaused(): void
    {
        $this->attackTick++;
        $this->ninjaActivationTick++;
        if ($this->lastActionTick !== 0) {
            $this->lastActionTick++;
        }
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        if (! $this->alive) {
            return [];
        }

        return [
            new ObjCharacterItem(
                tick: $this->world->isPaused() ? 0 : $this->tick, // Tick zero disables client dead reckoning while the world is paused (from 0.6 source).
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
                ammoCount: $this->weapons[$this->activeWeapon]->ammo,
                weapon: $this->activeWeapon,
                emote: $this->emote,
                attackTick: $this->attackTick,
            ),
        ];
    }

    protected function makeIdleInput(): PlayerInput
    {
        return new PlayerInput(
            direction: 0,
            targetX: 0,
            targetY: -1,
            jump: false,
            fire: 0,
            hook: false,
            playerFlags: 0,
            wantedWeapon: 0,
            nextWeapon: 0,
            prevWeapon: 0,
        );
    }
}
