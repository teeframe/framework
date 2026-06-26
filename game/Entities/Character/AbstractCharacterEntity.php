<?php

namespace TeeFrame\Game\Entities\Character;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\AbstractEntity;
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

    public CharacterCore $core;

    private bool $inputInitialized = false;

    public function __construct(AbstractWorld $world, public Vector2 $position)
    {
        parent::__construct(world: $world, position: $position);

        $this->core = new CharacterCore($position);
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

        $this->core = new CharacterCore($pos);
        $this->core->direction = 1;

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
        $this->world->getGameController()->onCharacterDeath($this, $killerTeeIndex);

        // Set respawn on the tee
        if ($this->tee instanceof PlayerTee) {
            $respawnDelay = $killerTeeIndex === -1 ? 150 : 25; // 3s for self-kill, 0.5s normal
            $this->tee->respawnTick = $this->world->getCurrentTick() + $respawnDelay;
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
        $this->ninjaActivationTick = $this->world->getCurrentTick();
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

        $this->core->vel->x += $force->x;
        $this->core->vel->y += $force->y;

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
        if ($damage > 0) {
            $this->createSound($damage > 2 ? GameConstants::SOUND_PLAYER_PAIN_LONG : GameConstants::SOUND_PLAYER_PAIN_SHORT);
        }

        if ($this->health <= 0) {
            $killerTeeIndex = $inflictor->tee !== null ? $inflictor->tee->teeIndex : -1;
            $this->die($killerTeeIndex);

            // Add death event
            $teeIndex = $this->tee instanceof AbstractTee ? $this->tee->teeIndex : -1;
            $this->world->addEvent(new \TeeFrame\Network\SnapItems\ObjEventDeathItem(
                x: (int) round($this->position->x),
                y: (int) round($this->position->y),
                clientId: $teeIndex,
            ));
        }
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

        // Build otherCores array keyed by tee index
        $otherCores = $this->buildOtherCores();

        $tune = $this->world->getTuneController();
        $this->core->tick($direction, $targetX, $targetY, $jump, $hook, $collision, $tune, $otherCores);
        $this->core->move($collision, $tune);

        // Sync position from core
        $this->position->x = $this->core->position->x;
        $this->position->y = $this->core->position->y;

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

    /**
     * Build an array of other characters' cores, keyed by tee index.
     *
     * @return array<int, CharacterCore>
     */
    private function buildOtherCores(): array
    {
        $cores = [];

        foreach ($this->world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || $entity === $this || ! $entity->alive) {
                continue;
            }

            if ($entity->tee !== null) {
                $cores[$entity->tee->teeIndex] = $entity->core;
            }
        }

        return $cores;
    }

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

    // -- Shooting --

    /**
     * Handle weapon firing. Override in game-mode subclasses for custom weapon logic.
     */
    protected function handleWeapons(bool $firePressed): void
    {
    }

    abstract protected function shootHammer(): int;

    abstract protected function shootGun(): int;

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
                velX: (int) round($this->core->vel->x * 256.0),
                velY: (int) round($this->core->vel->y * 256.0),
                angle: $this->core->angle,
                direction: $this->core->direction,
                jumped: $this->core->jumped,
                hookedPlayer: $this->core->hookedPlayer,
                hookState: $this->core->hookState,
                hookTick: $this->core->hookTick,
                hookX: (int) round($this->core->hookPos->x),
                hookY: (int) round($this->core->hookPos->y),
                hookDx: (int) round($this->core->hookDir->x * 256.0),
                hookDy: (int) round($this->core->hookDir->y * 256.0),
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

    protected function createSound(int $soundId): void
    {
        $this->world->addEvent(new ObjEventSoundWorldItem(
            x: (int) round($this->position->x),
            y: (int) round($this->position->y),
            soundId: $soundId,
        ));
    }
}