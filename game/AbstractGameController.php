<?php

namespace TeeFrame\Game;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractGameController implements SnapableObject, TickableObject
{
    public const SPAWN_HUMAN = 0;
    public const SPAWN_RED   = 1;
    public const SPAWN_BLUE  = 2;

    public const INACTIVE_KICK_TO_SPECTATOR = 0;
    public const INACTIVE_KICK_SPEC_OR_KICK = 1;
    public const INACTIVE_KICK_KICK         = 2;

    /**
     * @var array<int, Vector2[]>
     */
    protected array $spawnPoints = [
        self::SPAWN_HUMAN => [],
        self::SPAWN_RED   => [],
        self::SPAWN_BLUE  => [],
    ];

    protected int $roundStartTick = 0;
    protected int $gameOverTick   = -1;
    protected int $suddenDeath    = 0;
    protected int $warmup         = 0;
    protected int $unpauseTimer   = 0;
    protected int $roundCount     = 0;

    protected int  $unbalancedTick  = -1;
    protected bool $forceBalanced   = false;

    /**
     * @var array<int, FlagEntity|null>
     */
    protected array $flags = [null, null];

    protected ?AbstractWorld $world = null;

    public function __construct(
        protected TickHandler $tickHandler,
        protected bool $isTeamMode = false,
        protected bool $isCaptureTheFlag = false,
        protected int $scoreLimit       = 0,
        protected int $timeLimit        = 0,
        protected int $teamBalanceTime   = 0,
        protected int $inactiveKickTime  = 0,
        protected int $inactiveKick      = self::INACTIVE_KICK_TO_SPECTATOR,
        protected int $spectatorSlots    = 0,
    ) {
        $this->roundStartTick = $tickHandler->get();
    }

    public function setWorld(AbstractWorld $world): void
    {
        $this->world = $world;
    }

    public function getWorld(): ?AbstractWorld
    {
        return $this->world;
    }

    /*
    |--------------------------------------------------------------------------
    | Tick lifecycle
    |--------------------------------------------------------------------------
    */

    public function doTick(): void
    {
        $world = $this->world;
        $paused = $world !== null && $world->isPaused();

        if (! $paused && $this->warmup > 0) {
            $this->warmup--;
            if ($this->warmup === 0) {
                $this->startRound();
            }
        }

        if ($this->gameOverTick !== -1) {
            if ($this->tickHandler->get() > $this->gameOverTick + NetworkParams::TICKS_PER_SECOND * 10) {
                $this->cycleMap();
                $this->startRound();
                $this->roundCount++;
            }
        } elseif ($paused && $this->unpauseTimer > 0) {
            --$this->unpauseTimer;
            if ($this->unpauseTimer === 0) {
                $world->setPaused(false);
            }
        }

        if ($paused) {
            $this->roundStartTick++;
        }

        if ($this->isTeamMode && $this->teamBalanceTime > 0 && $this->unbalancedTick !== -1
            && $this->tickHandler->get() > $this->unbalancedTick + $this->teamBalanceTime * NetworkParams::TICKS_PER_SECOND * 60
        ) {
            $this->balanceTeams();
            $this->unbalancedTick = -1;
        }

        if ($this->inactiveKickTime > 0 && $world !== null) {
            $this->checkInactivePlayers();
        }

        if ($this->isCaptureTheFlag && $world !== null && ! $world->isResetRequested() && ! $world->isPaused()) {
            $this->tickFlags();
        }

        if (! $this->isGameOver()) {
            $this->doWincheck();
        }
    }

    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        $hadFlag = $this->dropFlagsOnDeath($victim, $killerTeeIndex); // If not capture the flag, it will return 0

        if ($killerTeeIndex < 0) {
            return $hadFlag;
        }

        // Find the killer's tee
        $killerTee = null;
        $world = $this->getWorld();
        if ($world !== null) {
            foreach ($world->getTees() as $tee) {
                if ($tee->teeIndex === $killerTeeIndex) {
                    $killerTee = $tee;
                    break;
                }
            }
        }

        if ($killerTee === null) {
            return $hadFlag;
        }

        $victimTee = $victim->tee;

        // Suicide check
        if ($killerTeeIndex === ($victimTee !== null ? $victimTee->teeIndex : -1)) {
            if ($killerTee instanceof PlayerTee) {
                $killerTee->score--;
            }

            return $hadFlag;
        }

        // Normal kill
        if ($killerTee instanceof PlayerTee) {
            $killerTee->score++;
        }

        return $hadFlag;
    }

    public function doWincheck(): void
    {
        $world = $this->world;
        if ($this->gameOverTick !== -1 || $this->warmup > 0 || $world === null || $world->isResetRequested()) {
            return;
        }

        $elapsed = $this->tickHandler->get() - $this->roundStartTick;
        $timeLimitReached = $this->timeLimit > 0 && $elapsed >= $this->timeLimit * NetworkParams::TICKS_PER_SECOND * 60;

        if ($this->isTeamMode) {
            $teamScore = [0, 0];
            foreach ($world->getTees() as $tee) {
                if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                    continue;
                }
                if ($tee->team === GameConstants::TEAM_RED || $tee->team === GameConstants::TEAM_BLUE) {
                    $teamScore[$tee->team] += $tee->score;
                }
            }

            $scoreLimitReached = $this->scoreLimit > 0
                && ($teamScore[GameConstants::TEAM_RED] >= $this->scoreLimit
                    || $teamScore[GameConstants::TEAM_BLUE] >= $this->scoreLimit);

            if ($scoreLimitReached || $timeLimitReached) {
                if ($this->suddenDeath) {
                    $red  = $this->isCaptureTheFlag
                        ? (int) ($teamScore[GameConstants::TEAM_RED] / 100)
                        : $teamScore[GameConstants::TEAM_RED];
                    $blue = $this->isCaptureTheFlag
                        ? (int) ($teamScore[GameConstants::TEAM_BLUE] / 100)
                        : $teamScore[GameConstants::TEAM_BLUE];
                    if ($red !== $blue) {
                        $this->endRound();
                    }
                } elseif ($teamScore[GameConstants::TEAM_RED] !== $teamScore[GameConstants::TEAM_BLUE]) {
                    $this->endRound();
                } else {
                    $this->suddenDeath = 1;
                }
            }
        } else {
            $topScore = 0;
            $topScoreCount = 0;
            foreach ($world->getTees() as $tee) {
                if (! $tee instanceof PlayerTee) {
                    continue;
                }
                if ($tee->score > $topScore) {
                    $topScore = $tee->score;
                    $topScoreCount = 1;
                } elseif ($tee->score === $topScore) {
                    $topScoreCount++;
                }
            }

            $scoreLimitReached = $this->scoreLimit > 0 && $topScore >= $this->scoreLimit;

            if ($scoreLimitReached || $timeLimitReached) {
                if ($topScoreCount === 1) {
                    $this->endRound();
                } else {
                    $this->suddenDeath = 1;
                }
            }
        }
    }

    public function doWarmup(int $seconds): void
    {
        $this->warmup = $seconds < 0 ? 0 : $seconds * NetworkParams::TICKS_PER_SECOND;
    }

    public function togglePause(): void
    {
        $world = $this->world;
        if ($world === null || $this->isGameOver()) {
            return;
        }

        if ($world->isPaused()) {
            if ($this->unpauseTimer > 0) {
                return;
            }
            $world->setPaused(false);
            $this->unpauseTimer = 0;
        } else {
            $world->setPaused(true);
            $this->unpauseTimer = 0;
        }
    }

    public function startRound(): void
    {
        $world = $this->world;
        if ($world !== null) {
            $world->resetGame();
        }

        $this->roundStartTick = $this->tickHandler->get();
        $this->suddenDeath    = 0;
        $this->gameOverTick   = -1;
        if ($world !== null) {
            $world->setPaused(false);
        }
        $this->forceBalanced = false;
    }

    public function endRound(): void
    {
        if ($this->warmup > 0) {
            return;
        }

        $world = $this->world;
        if ($world !== null) {
            $world->setPaused(true);
        }
        $this->gameOverTick = $this->tickHandler->get();
        $this->suddenDeath  = 0;
    }

    public function cycleMap(): void
    {
    }

    public function isGameOver(): bool
    {
        return $this->gameOverTick !== -1;
    }

    public function isTeamplay(): bool
    {
        return $this->isTeamMode;
    }

    public function isForceBalanced(): bool
    {
        if ($this->forceBalanced) {
            $this->forceBalanced = false;
            return true;
        }
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Team management
    |--------------------------------------------------------------------------
    */

    public function canBeMovedOnBalance(int $teeIndex): bool
    {
        if ($this->isCaptureTheFlag) {
            $character = $this->findCharacterByTeeIndex($teeIndex);
            if ($character !== null) {
                for ($i = 0; $i < 2; $i++) {
                    $flag = $this->flags[$i];
                    if ($flag !== null && $flag->carryingCharacter === $character) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function isFriendlyFire(int $victimTeeIndex, int $killerTeeIndex): bool
    {
        if ($victimTeeIndex === $killerTeeIndex) {
            return false;
        }

        if (! $this->isTeamMode || $this->world === null) {
            return false;
        }

        $victimTee = null;
        $killerTee = null;
        foreach ($this->world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }
            if ($tee->teeIndex === $victimTeeIndex) {
                $victimTee = $tee;
            }
            if ($tee->teeIndex === $killerTeeIndex) {
                $killerTee = $tee;
            }
        }

        if ($victimTee === null || $killerTee === null) {
            return false;
        }

        return $victimTee->team === $killerTee->team;
    }

    public function getAutoTeam(int $notThisTeeIndex): int
    {
        $world = $this->world;
        if ($world === null) {
            return GameConstants::TEAM_RED;
        }

        $numPlayers = [0, 0];
        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->teeIndex === $notThisTeeIndex) {
                continue;
            }
            if ($tee->team === GameConstants::TEAM_RED || $tee->team === GameConstants::TEAM_BLUE) {
                $numPlayers[$tee->team]++;
            }
        }

        $team = GameConstants::TEAM_RED;
        if ($this->isTeamMode) {
            $team = $numPlayers[GameConstants::TEAM_RED] > $numPlayers[GameConstants::TEAM_BLUE]
                ? GameConstants::TEAM_BLUE
                : GameConstants::TEAM_RED;
        }

        if ($this->canJoinTeam($team, $notThisTeeIndex)) {
            return $team;
        }

        return GameConstants::TEAM_SPECTATORS;
    }

    public function canJoinTeam(int $team, int $notThisTeeIndex): bool
    {
        $world = $this->world;
        if ($world === null) {
            return false;
        }

        if ($team === GameConstants::TEAM_SPECTATORS) {
            return true;
        }

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }
            if ($tee->teeIndex === $notThisTeeIndex && $tee->team !== GameConstants::TEAM_SPECTATORS) {
                return true;
            }
        }

        $numPlayers = 0;
        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->teeIndex === $notThisTeeIndex) {
                continue;
            }
            if ($tee->team === GameConstants::TEAM_RED || $tee->team === GameConstants::TEAM_BLUE) {
                $numPlayers++;
            }
        }

        return $numPlayers < 64 - $this->spectatorSlots;
    }

    public function checkTeamBalance(): bool
    {
        if (! $this->isTeamMode || $this->teamBalanceTime === 0) {
            return true;
        }

        $world = $this->world;
        if ($world === null) {
            return true;
        }

        $aT = [0, 0];
        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }
            if ($tee->team !== GameConstants::TEAM_SPECTATORS) {
                $aT[$tee->team]++;
            }
        }

        if (abs($aT[0] - $aT[1]) >= 2) {
            if ($this->unbalancedTick === -1) {
                $this->unbalancedTick = $this->tickHandler->get();
            }
            return false;
        }

        $this->unbalancedTick = -1;
        return true;
    }

    protected function balanceTeams(): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        $aT = [0, 0];
        $aTScore = [0.0, 0.0];
        $aPScore = [];

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }
            $aT[$tee->team]++;
            $elapsed = $this->tickHandler->get() - $tee->scoreStartTick;
            $aPScore[$tee->teeIndex] = $elapsed > 0
                ? $tee->score * NetworkParams::TICKS_PER_SECOND * 60.0 / $elapsed
                : 0.0;
            $aTScore[$tee->team] += $aPScore[$tee->teeIndex];
        }

        if (abs($aT[0] - $aT[1]) < 2) {
            return;
        }

        $M = $aT[0] > $aT[1] ? 0 : 1;
        $numBalance = (int) (abs($aT[0] - $aT[1]) / 2);

        do {
            $candidate = null;
            $bestDiff = $aTScore[$M];

            foreach ($world->getTees() as $tee) {
                if (! $tee instanceof PlayerTee || $tee->team !== $M) {
                    continue;
                }
                if (! $this->canBeMovedOnBalance($tee->teeIndex)) {
                    continue;
                }

                $diff = abs(($aTScore[$M ^ 1] + $aPScore[$tee->teeIndex]) - ($aTScore[$M] - $aPScore[$tee->teeIndex]));
                if ($candidate === null || $diff < $bestDiff) {
                    $candidate = $tee;
                    $bestDiff = $diff;
                }
            }

            if ($candidate === null) {
                break;
            }

            $lastAction = $candidate->lastActionTick;
            $candidate->setTeam($M ^ 1);
            $candidate->lastActionTick = $lastAction;
            $candidate->forceBalanced = true;
        } while (--$numBalance > 0);

        $this->forceBalanced = true;
    }

    protected function checkInactivePlayers(): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        $threshold = $this->inactiveKickTime * NetworkParams::TICKS_PER_SECOND * 60;
        $currentTick = $this->tickHandler->get();

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }

            if ($currentTick <= $tee->lastActionTick + $threshold) {
                continue;
            }

            switch ($this->inactiveKick) {
                case self::INACTIVE_KICK_TO_SPECTATOR:
                    $tee->setTeam(GameConstants::TEAM_SPECTATORS);
                    break;

                case self::INACTIVE_KICK_SPEC_OR_KICK:
                    $spectators = 0;
                    foreach ($world->getTees() as $t) {
                        if ($t instanceof PlayerTee && $t->team === GameConstants::TEAM_SPECTATORS) {
                            $spectators++;
                        }
                    }
                    if ($spectators >= $this->spectatorSlots) {
                        $world->getServer()->kick($world, $tee->teeIndex, 'Kicked for inactivity');
                    } else {
                        $tee->setTeam(GameConstants::TEAM_SPECTATORS);
                    }
                    break;

                case self::INACTIVE_KICK_KICK:
                    $world->getServer()->kick($world, $tee->teeIndex, 'Kicked for inactivity');
                    break;
            }
        }
    }

    public function getTeamName(int $team): string
    {
        if ($this->isTeamMode) {
            return match ($team) {
                GameConstants::TEAM_RED  => 'red team',
                GameConstants::TEAM_BLUE => 'blue team',
                default                  => 'spectators',
            };
        }

        return $team === GameConstants::TEAM_RED ? 'game' : 'spectators';
    }

    /*
    |--------------------------------------------------------------------------
    | Spawning
    |--------------------------------------------------------------------------
    */

    public function collectSpawnPoints(GameLayer $gameLayer): void
    {
        foreach ($gameLayer->getEntityPositions() as $entity) {
            $pos = new Vector2($entity['x'], $entity['y']);

            match ($entity['type']) {
                GameLayer::ENTITY_SPAWN      => $this->spawnPoints[self::SPAWN_HUMAN][] = $pos,
                GameLayer::ENTITY_SPAWN_RED  => $this->spawnPoints[self::SPAWN_RED][]   = $pos,
                GameLayer::ENTITY_SPAWN_BLUE => $this->spawnPoints[self::SPAWN_BLUE][]  = $pos,
                default                      => null,
            };
        }

        if (empty($this->spawnPoints[self::SPAWN_RED])) {
            $this->spawnPoints[self::SPAWN_RED] = $this->spawnPoints[self::SPAWN_HUMAN];
        }
        if (empty($this->spawnPoints[self::SPAWN_BLUE])) {
            $this->spawnPoints[self::SPAWN_BLUE] = $this->spawnPoints[self::SPAWN_HUMAN];
        }
    }

    public function canSpawn(AbstractWorld $world, int $team): ?Vector2
    {
        if ($team < 0) {
            return null;
        }

        if ($this->isTeamMode) {
            $ownTeam     = 1 + ($team & 1);
            $enemyTeam   = 1 + (($team + 1) & 1);
            $spawnOrder  = [$ownTeam, self::SPAWN_HUMAN, $enemyTeam];
        } else {
            $spawnOrder = [self::SPAWN_HUMAN, self::SPAWN_RED, self::SPAWN_BLUE];
        }

        foreach ($spawnOrder as $type) {
            $pos = $this->evaluateSpawnType($world, $type);
            if ($pos !== null) {
                return $pos;
            }
        }

        foreach ($spawnOrder as $type) {
            if (! empty($this->spawnPoints[$type])) {
                return $this->spawnPoints[$type][0];
            }
        }

        return null;
    }

    protected function evaluateSpawnType(AbstractWorld $world, int $type): ?Vector2
    {
        if (empty($this->spawnPoints[$type])) {
            return null;
        }

        $bestPos   = null;
        $bestScore = 0.0;

        foreach ($this->spawnPoints[$type] as $pos) {
            $score = $this->evaluateSpawnPos($world, $pos);

            if ($bestPos === null || $score > $bestScore) {
                $bestPos   = $pos;
                $bestScore = $score;
            }
        }

        return $bestPos;
    }

    protected function evaluateSpawnPos(AbstractWorld $world, Vector2 $pos): float
    {
        $score = 0.0;

        foreach ($world->getTees() as $tee) {
            if ($tee->teeIndex < 0) {
                continue;
            }

            $distance = $pos->distance($tee->viewPosition);

            if ($distance < 100) {
                $score -= 1.0;
            } elseif ($distance < 200) {
                $score -= 0.5;
            } elseif ($distance < 400) {
                $score -= 0.2;
            }
        }

        return $score;
    }

    /*
    |--------------------------------------------------------------------------
    | Snap
    |--------------------------------------------------------------------------
    */

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        $snaps = [];

        $world = $this->world;
        $paused = $world !== null && $world->isPaused();

        $gameStateFlags = 0;
        if ($this->gameOverTick !== -1) {
            $gameStateFlags |= GameConstants::GAMESTATEFLAG_GAMEOVER;
        }
        if ($this->suddenDeath !== 0) {
            $gameStateFlags |= GameConstants::GAMESTATEFLAG_SUDDENDEATH;
        }
        if ($paused) {
            $gameStateFlags |= GameConstants::GAMESTATEFLAG_PAUSED;
        }

        $gameFlags = $this->isTeamMode ? GameConstants::GAMEFLAG_TEAMS : 0;
        if ($this->flags[GameConstants::TEAM_RED] !== null || $this->flags[GameConstants::TEAM_BLUE] !== null) {
            $gameFlags |= GameConstants::GAMEFLAG_FLAGS;
        }
        $warmupTimer = $paused ? $this->unpauseTimer : $this->warmup;

        $snaps[] = new ObjGameInfoItem(
            gameFlags: $gameFlags,
            gameStateFlags: $gameStateFlags,
            roundStartTick: $this->roundStartTick,
            warmupTimer: $warmupTimer,
            scoreLimit: $this->scoreLimit,
            timeLimit: $this->timeLimit,
            roundNum: 0,
            roundCurrent: $this->roundCount + 1,
        );

        if ($this->isTeamMode) {
            $teamScoreRed = 0;
            $teamScoreBlue = 0;
            if ($world !== null) {
                foreach ($world->getTees() as $tee) {
                    if (! $tee instanceof PlayerTee) {
                        continue;
                    }
                    if ($tee->team === GameConstants::TEAM_RED) {
                        $teamScoreRed += $tee->score;
                    } elseif ($tee->team === GameConstants::TEAM_BLUE) {
                        $teamScoreBlue += $tee->score;
                    }
                }
            }

            $snaps[] = new ObjGameDataItem(
                teamScoreRed: $teamScoreRed,
                teamScoreBlue: $teamScoreBlue,
                flagCarrierRedIndex: $this->getFlagCarrierId(GameConstants::TEAM_RED),
                flagCarrierBlueIndex: $this->getFlagCarrierId(GameConstants::TEAM_BLUE)
            );
        }

        return $snaps;
    }

    protected function getFlagCarrierId(int $team): int
    {
        $flag = $this->flags[$team] ?? null;
        if ($flag === null) {
            return GameConstants::FLAG_MISSING;
        }

        if ($flag->atStand) {
            return GameConstants::FLAG_ATSTAND;
        }

        if ($flag->carryingCharacter !== null && $flag->carryingCharacter->tee !== null) {
            return $flag->carryingCharacter->tee->teeIndex;
        }

        return GameConstants::FLAG_TAKEN;
    }

    /*
    |--------------------------------------------------------------------------
    | CTF flag mechanics
    |--------------------------------------------------------------------------
    */

    public function getFlag(int $team): ?FlagEntity
    {
        return $this->flags[$team] ?? null;
    }

    /**
     * @return array<int, FlagEntity|null>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    public function collectFlagPoints(GameLayer $gameLayer): void
    {
        if (! $this->isCaptureTheFlag || $this->world === null) {
            return;
        }

        foreach ($gameLayer->getEntityPositions() as $entity) {
            $team = match ($entity['type']) {
                GameLayer::ENTITY_FLAGSTAND_RED  => GameConstants::TEAM_RED,
                GameLayer::ENTITY_FLAGSTAND_BLUE => GameConstants::TEAM_BLUE,
                default                          => -1,
            };

            if ($team === -1 || $this->flags[$team] !== null) {
                continue;
            }

            $pos = new Vector2($entity['x'], $entity['y']);
            $flag = new FlagEntity($this->world, $pos, $team);
            $this->flags[$team] = $flag;
            $this->world->addEntity($flag);
        }
    }

    protected function tickFlags(): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        $collision = $world->getMap()->getCollision();
        $currentTick = $this->tickHandler->get();

        for ($fi = 0; $fi < 2; $fi++) {
            $flag = $this->flags[$fi];
            if ($flag === null) {
                continue;
            }

            $flagPos = $flag->getPosition();
            if ($collision !== null && ($collision->getCollisionAt($flagPos->x, $flagPos->y) & Collision::COLFLAG_DEATH)) {
                $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                $flag->reset();
                continue;
            }

            if ($flag->carryingCharacter !== null) {
                $this->tickCarriedFlag($flag, $fi, $currentTick);
            } else {
                $this->tickDroppedOrAtStandFlag($flag, $fi, $collision, $currentTick);
            }
        }
    }

    protected function tickCarriedFlag(FlagEntity $flag, int $fi, int $currentTick): void
    {
        $carrier = $flag->carryingCharacter;
        if ($carrier === null || ! $carrier->alive) {
            $flag->carryingCharacter = null;
            $flag->dropTick = $currentTick;
            return;
        }

        $flag->setPosition(clone $carrier->getPosition());

        $enemyFlag = $this->flags[$fi ^ 1] ?? null;
        if ($enemyFlag === null || ! $enemyFlag->atStand) {
            return;
        }

        $captureDistance = FlagEntity::PHYS_SIZE + AbstractCharacterEntity::PHYS_SIZE;
        if ($flag->getPosition()->distance($enemyFlag->getPosition()) >= $captureDistance) {
            return;
        }

        $this->onFlagCapture($flag, $fi);
    }

    protected function onFlagCapture(FlagEntity $flag, int $fi): void
    {
        $world = $this->world;
        $carrier = $flag->carryingCharacter;
        $carrierTee = $carrier !== null ? $carrier->tee : null;

        if ($carrierTee instanceof PlayerTee) {
            $carrierTee->score += 100;
        }

        $flag->reset();
        $enemyFlag = $this->flags[$fi ^ 1] ?? null;
        if ($enemyFlag !== null) {
            $enemyFlag->reset();
        }

        $world?->createSoundGlobal(GameConstants::SOUND_CTF_CAPTURE);
    }

    protected function tickDroppedOrAtStandFlag(FlagEntity $flag, int $fi, ?Collision $collision, int $currentTick): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        foreach ($world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }

            $playerTee = $entity->tee;
            if (! $playerTee instanceof PlayerTee) {
                continue;
            }

            if ($playerTee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }

            if ($collision !== null) {
                [$hitFlags] = $collision->intersectLine($flag->getPosition(), $entity->getPosition());
                if ($hitFlags !== 0) {
                    continue;
                }
            }

            $distance = $flag->getPosition()->distance($entity->getPosition());
            if ($distance >= FlagEntity::PHYS_SIZE + $entity->getHitBoxRadius()) {
                continue;
            }

            if ($playerTee->team === $flag->team) {
                if (! $flag->atStand) {
                    $playerTee->score += 1;
                    $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                    $flag->reset();
                }
            } else {
                $this->onFlagGrab($flag, $fi, $entity, $currentTick);
            }

            break;
        }

        if ($flag->carryingCharacter === null && ! $flag->atStand) {
            if ($currentTick > $flag->dropTick + NetworkParams::TICKS_PER_SECOND * 30) {
                $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                $flag->reset();
            } elseif ($collision !== null) {
                $flag->vel->y += $world->getTuneController()->gravity;
                $collision->moveBox(
                    $flag->getPosition(),
                    $flag->vel,
                    new Vector2(FlagEntity::PHYS_SIZE, FlagEntity::PHYS_SIZE),
                    0.5,
                );
            }
        }
    }

    protected function onFlagGrab(FlagEntity $flag, int $fi, AbstractCharacterEntity $carrier, int $currentTick): void
    {
        $world = $this->world;
        $carrierTee = $carrier->tee;

        if ($flag->atStand) {
            if ($carrierTee instanceof PlayerTee) {
                $carrierTee->score += 1;
            }
            $flag->grabTick = $currentTick;
        }

        $flag->atStand = false;
        $flag->carryingCharacter = $carrier;

        if ($carrierTee instanceof PlayerTee) {
            $carrierTee->score += 1;
        }

        if ($world !== null) {
            foreach ($world->getTees() as $tee) {
                if (! $tee instanceof PlayerTee) {
                    continue;
                }

                $soundId = $tee->team === $flag->team
                    ? GameConstants::SOUND_CTF_GRAB_EN
                    : GameConstants::SOUND_CTF_GRAB_PL;

                $world->createSoundGlobalFor($soundId, $tee->teeIndex);
            }
        }
    }

    protected function dropFlagsOnDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        $hadFlag = 0;

        for ($i = 0; $i < 2; $i++) {
            $flag = $this->flags[$i];
            if ($flag === null) {
                continue;
            }

            $killerCharacter = $this->findCharacterByTeeIndex($killerTeeIndex);
            if ($killerCharacter !== null && $flag->carryingCharacter === $killerCharacter) {
                $hadFlag |= 2;
            }

            if ($flag->carryingCharacter === $victim) {
                $world = $this->world;
                $world?->createSoundGlobal(GameConstants::SOUND_CTF_DROP);

                $flag->dropTick = $this->tickHandler->get();
                $flag->carryingCharacter = null;
                $flag->vel = new Vector2(0, 0);

                $killerTee = $this->findTeeByIndex($killerTeeIndex);
                $victimTee = $victim->tee;
                if ($killerTee instanceof PlayerTee && $victimTee instanceof PlayerTee
                    && $killerTee->team !== $victimTee->team) {
                    $killerTee->score++;
                }

                $hadFlag |= 1;
            }
        }

        return $hadFlag;
    }

    /*
    |--------------------------------------------------------------------------
    | Lookup helpers
    |--------------------------------------------------------------------------
    */

    protected function findCharacterByTeeIndex(int $teeIndex): ?AbstractCharacterEntity
    {
        $world = $this->world;
        if ($world === null) {
            return null;
        }

        foreach ($world->getEntities() as $entity) {
            if (! $entity instanceof AbstractCharacterEntity || ! $entity->alive) {
                continue;
            }
            if ($entity->tee !== null && $entity->tee->teeIndex === $teeIndex) {
                return $entity;
            }
        }

        return null;
    }

    protected function findTeeByIndex(int $teeIndex): ?PlayerTee
    {
        $world = $this->world;
        if ($world === null) {
            return null;
        }

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee) {
                continue;
            }
            if ($tee->teeIndex === $teeIndex) {
                return $tee;
            }
        }

        return null;
    }
}
