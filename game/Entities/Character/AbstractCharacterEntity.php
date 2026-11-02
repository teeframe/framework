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
    public int $armor  = 0;

    public int $activeWeapon = GameConstants::WEAPON_GUN;
    public int $lastWeapon   = GameConstants::WEAPON_HAMMER;
    public int $queuedWeapon = -1;

    public bool $alive     = false;
    public int $tick       = 0;
    public int $attackTick = 0;

    public int $emote            = GameConstants::EMOTE_NORMAL;
    protected int $emoteStopTick = -1;

    public int $reloadTimer = 0;

    /**
     * @var array<int, CharacterWeaponState>
     */
    public array $weapons = [];

    // Ninja state
    public int $ninjaActivationTick = 0;
    public Vector2 $ninjaActivationDir;
    public int $ninjaCurrentMoveTime = 0;
    public float $ninjaOldVelAmount  = 0.0;
    /**
     * @var list<AbstractCharacterEntity>
     */
    public array $ninjaHitObjects = [];

    public ?AbstractTee $tee = null;

    // Input state (mirrors CCharacter::m_Input / m_LatestInput / m_LatestPrevInput)
    protected PlayerInput $input;
    protected PlayerInput $latestInput;
    protected PlayerInput $latestPrevInput;
    protected int $numInputs = 0;

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
        if ($this->tee instanceof PlayerTee
            && ($this->tee->playerFlags & GameConstants::PLAYERFLAG_CHATTING)
            && ($newInput->playerFlags & GameConstants::PLAYERFLAG_CHATTING)
        ) {
            return;
        }

        $this->input = $this->normalizeInputTarget($newInput);
        $this->numInputs++;
    }

    public function onDirectInput(PlayerInput $newInput): void
    {
        if (! $this->alive || $this->world->isPaused()) {
            return;
        }

        $this->latestPrevInput = $this->latestInput;
        $this->latestInput     = $this->normalizeInputTarget($newInput);

        if ($this->numInputs > 2 && $this->tee instanceof PlayerTee && $this->tee->team !== GameConstants::TEAM_SPECTATORS) {
            $this->handleWeaponSwitch();
            $this->fireWeapon();
        }

        // Consume presses even when a cooldown prevented the action (CCharacter::OnDirectInput).
        $this->latestPrevInput = $this->latestInput;
    }

    public function resetInput(): void
    {
        // Release fire without generating another press (CCharacter::ResetInput).
        $fire = $this->input->fire;

        if ($fire & 1) {
            $fire++;
        }

        $this->input = new PlayerInput(
            direction: 0,
            targetX: $this->input->targetX,
            targetY: $this->input->targetY,
            jump: false,
            fire: $fire & self::INPUT_STATE_MASK,
            hook: false,
            playerFlags: $this->input->playerFlags,
            wantedWeapon: $this->input->wantedWeapon,
            nextWeapon: $this->input->nextWeapon,
            prevWeapon: $this->input->prevWeapon,
        );
        $this->latestInput     = $this->input;
        $this->latestPrevInput = $this->input;
    }

    abstract protected function handleWeapons(): void;

    abstract protected function fireWeapon(): void;

    public function onTeeLeave(int $teeIndex): void
    {
        if ($this->hookedPlayer === $teeIndex) {
            $this->hookedPlayer = -1;
            $this->hookState    = GameConstants::HOOK_RETRACTED;
            $this->hookPos      = $this->position;
        }
    }

    public function getFixedSnapId(): ?int
    {
        $teeIndex = $this->tee?->teeIndex;

        return $teeIndex !== null && $teeIndex >= 0 ? $teeIndex : null;
    }

    public function getHitBoxRadius(): int
    {
        return self::PHYS_SIZE;
    }

    public function getSnapDistance(Vector2 $viewPosition): float
    {
        if ($this->hookState <= GameConstants::HOOK_IDLE) {
            return parent::getSnapDistance($viewPosition);
        }

        $closest = $viewPosition->closestPointOnSegment($this->position, $this->hookPos);

        return $viewPosition->distance($closest);
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

        $tune = $this->world->getTuneController();
        $this->tickCore(
            $this->input->direction,
            $this->input->targetX,
            $this->input->targetY,
            $this->input->jump,
            $this->input->hook,
            $collision,
            $tune,
            $this->getOtherCharacters(),
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

        $this->handleWeapons();
    }

    public function doTickDeferred(): void
    {
        if (! $this->alive) {
            return;
        }

        $collision = $this->world->getMap()->getCollision();

        if ($collision === null) {
            return;
        }

        $tune = $this->world->getTuneController();

        // Ninja must not retain drag that would be applied after its dash.
        if ($this->activeWeapon !== GameConstants::WEAPON_NINJA || $this->ninjaCurrentMoveTime < 0) {
            $this->addDragVelocity($tune);
        }

        $this->resetDragVelocity();

        $this->move($collision, $tune, $this->getOtherCharacters());
        $this->quantize();

        if ($this->tee !== null) {
            $this->tee->viewPosition = clone $this->position;
        }

        // The local client predicts ground-jump and ground/no-hook sounds, but
        // other clients need server events (CCharacter::TickDefered in 0.6).
        $events           = $this->triggeredEvents;
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
    }

    public function doTickPaused(): void
    {
        $this->attackTick++;
        $this->ninjaActivationTick++;

        if ($this->emoteStopTick >= 0) {
            $this->emoteStopTick++;
        }

    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        if (! $this->alive) {
            return [];
        }

        if ($this->emoteStopTick >= 0 && $this->emoteStopTick < $this->world->getCurrentTick()) {
            $this->emote         = GameConstants::EMOTE_NORMAL;
            $this->emoteStopTick = -1;
        }

        $canViewStats = $this->canViewStats($requestingTee);

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
                playerFlags: $this->tee->playerFlags ?? 0,
                health: $canViewStats ? $this->health : 0,
                armor: $canViewStats ? $this->armor : 0,
                ammoCount: $canViewStats ? max(0, $this->weapons[$this->activeWeapon]->ammo) : 0,
                weapon: $this->activeWeapon,
                emote: $this->emote,
                attackTick: $this->attackTick,
            ),
        ];
    }

    protected function canViewStats(AbstractTee $requestingTee): bool
    {
        if ($requestingTee === $this->tee) {
            return true;
        }

        return ! $this->world->getConfig()->getInt('sv_world_hide_stats_from_spectators')
            && $this->tee !== null
            && $this->tee->teeIndex >= 0
            && $requestingTee instanceof PlayerTee
            && $requestingTee->team === GameConstants::TEAM_SPECTATORS
            && $requestingTee->spectatorId === $this->tee->teeIndex;
    }

    /** @return array<int, AbstractCharacterEntity> */
    protected function getOtherCharacters(): array
    {
        $characters = [];

        foreach ($this->world->getEntities() as $entity) {
            if ($entity instanceof AbstractCharacterEntity
                && $entity !== $this
                && $entity->alive
                && ! $entity->isMarkedForDestruction()
                && $entity->tee !== null
            ) {
                $characters[$entity->tee->teeIndex] = $entity;
            }
        }

        return $characters;
    }

    protected function normalizeInputTarget(PlayerInput $input): PlayerInput
    {
        if ($input->targetX !== 0 || $input->targetY !== 0) {
            return $input;
        }

        // It is not allowed to aim in the center.
        return new PlayerInput(
            direction: $input->direction,
            targetX: 0,
            targetY: -1,
            jump: $input->jump,
            fire: $input->fire,
            hook: $input->hook,
            playerFlags: $input->playerFlags,
            wantedWeapon: $input->wantedWeapon,
            nextWeapon: $input->nextWeapon,
            prevWeapon: $input->prevWeapon,
        );
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
