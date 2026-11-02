<?php

namespace TeeFrame\Game;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\FlagEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Collision;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

class PvpGameController extends AbstractGameController
{
    protected int $roundStartTick = 0;
    protected int $gameOverTick   = -1;
    protected int $suddenDeath    = 0;
    protected int $warmup         = 0;
    protected int $unpauseTimer   = 0;
    protected int $roundCount     = 0;
    protected int $unbalancedTick = -1;
    protected bool $forceBalanced = false;

    /** @var array{int, int} */
    protected array $teamScores = [0, 0];

    /** @var array<int, FlagEntity|null> */
    protected array $flags = [null, null];

    public function __construct(
        TickHandler $tickHandler,
        protected bool $isTeamMode = false,
        protected bool $isCaptureTheFlag = false,
        protected int $scoreLimit = 0,
        protected int $timeLimit = 0,
        protected int $teamBalanceTime = 0,
        int $inactiveKickTime = 0,
        int $inactiveKick = self::INACTIVE_KICK_TO_SPECTATOR,
        int $spectatorSlots = 0,
    ) {
        parent::__construct($tickHandler, $inactiveKickTime, $inactiveKick, $spectatorSlots);

        $this->roundStartTick = $tickHandler->get();
    }

    public function getGameType(): string
    {
        return $this->isCaptureTheFlag ? 'CTF' : ($this->isTeamMode ? 'TDM' : 'DM');
    }

    public function setGameMode(string $gameMode): void
    {
        [$this->isTeamMode, $this->isCaptureTheFlag] = match (strtoupper($gameMode)) {
            'DM'    => [false, false],
            'TDM'   => [true, false],
            'CTF'   => [true, true],
            default => throw new \RuntimeException('Unsupported gamemode: '.$gameMode),
        };
    }

    public function bootMap(GameLayer $gameLayer): void
    {
        parent::bootMap($gameLayer);

        $this->collectFlagPoints($gameLayer);
    }

    /*
    |--------------------------------------------------------------------------
    | Tick lifecycle
    |--------------------------------------------------------------------------
    */

    public function doTick(): void
    {
        $world  = $this->world;
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
            $this->unpauseTimer--;
            if ($this->unpauseTimer === 0) {
                $world->setPaused(false);
            }
        }

        if ($paused) {
            $this->roundStartTick++;
        }

        // A round restart or unpause timer can resume the world during this tick.
        if ($world !== null && $world->isPaused()) {
            if ($this->unbalancedTick !== -1) {
                $this->unbalancedTick++;
            }
        } elseif ($this->isTeamMode && $this->teamBalanceTime > 0 && $this->unbalancedTick !== -1
            && $this->tickHandler->get() > $this->unbalancedTick + $this->teamBalanceTime * NetworkParams::TICKS_PER_SECOND * 60
        ) {
            $this->balanceTeams();
            $this->unbalancedTick = -1;
        }

        parent::doTick();
    }

    public function tickDeferred(): void
    {
        $world = $this->world;
        if ($this->isCaptureTheFlag && $world !== null && ! $world->isResetRequested() && ! $world->isPaused()) {
            $this->tickFlags();
        }

        if (! $this->isGameOver()) {
            $this->doWincheck();
        }
    }

    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex, int $weapon): int
    {
        $hadFlag   = $this->dropFlagsOnDeath($victim, $killerTeeIndex);
        $killerTee = $this->world?->findTee($killerTeeIndex);
        if (! $killerTee instanceof PlayerTee || $weapon === GameConstants::WEAPON_GAME) {
            return $hadFlag;
        }

        $victimTee = $victim->tee;
        $suicide   = $victimTee === $killerTee;
        $teamkill  = $this->isTeamMode && $victimTee instanceof PlayerTee && $victimTee->team === $killerTee->team;
        $points    = $suicide || $teamkill ? -1 : 1;
        $killerTee->score += $points;

        if ($this->isTeamMode && ! $this->isCaptureTheFlag
            && in_array($killerTee->team, [GameConstants::TEAM_RED, GameConstants::TEAM_BLUE], true)
        ) {
            $this->teamScores[$killerTee->team] += $points;
        }

        return $hadFlag;
    }

    public function doWincheck(): void
    {
        $world = $this->world;
        if ($this->gameOverTick !== -1 || $this->warmup > 0 || $world === null || $world->isResetRequested()) {
            return;
        }

        $elapsed          = $this->tickHandler->get() - $this->roundStartTick;
        $timeLimitReached = $this->timeLimit > 0 && $elapsed >= $this->timeLimit * NetworkParams::TICKS_PER_SECOND * 60;

        if ($this->isTeamMode) {
            $teamScore = $this->getTeamScores();

            $scoreLimitReached = $this->scoreLimit > 0
                && ($teamScore[GameConstants::TEAM_RED] >= $this->scoreLimit
                    || $teamScore[GameConstants::TEAM_BLUE] >= $this->scoreLimit);

            if (! $scoreLimitReached && ! $timeLimitReached) {
                return;
            }

            if ($this->suddenDeath) {
                $red = $this->isCaptureTheFlag
                    ? (int) ($teamScore[GameConstants::TEAM_RED] / 100)
                    : $teamScore[GameConstants::TEAM_RED];
                $blue = $this->isCaptureTheFlag
                    ? (int) ($teamScore[GameConstants::TEAM_BLUE] / 100)
                    : $teamScore[GameConstants::TEAM_BLUE];
                if ($red !== $blue) {
                    $this->endRound();
                }

                return;
            }

            if ($teamScore[GameConstants::TEAM_RED] !== $teamScore[GameConstants::TEAM_BLUE]) {
                $this->endRound();
            } else {
                $world->consoleInfo('game', 'Sudden death');
                $this->suddenDeath = 1;
            }

            return;
        }

        $topScore      = 0;
        $topScoreCount = 0;
        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }
            if ($topScoreCount === 0 || $tee->score > $topScore) {
                $topScore      = $tee->score;
                $topScoreCount = 1;
            } elseif ($tee->score === $topScore) {
                $topScoreCount++;
            }
        }

        if ($topScoreCount === 0) {
            return;
        }

        $scoreLimitReached = $this->scoreLimit > 0 && $topScore >= $this->scoreLimit;
        if (! $scoreLimitReached && ! $timeLimitReached) {
            return;
        }

        if ($topScoreCount === 1) {
            $this->endRound();
        } else {
            if ($this->suddenDeath === 0) {
                $world->consoleInfo('game', 'Sudden death');
            }
            $this->suddenDeath = 1;
        }
    }

    public function doWarmup(int $seconds): void
    {
        $this->warmup = $seconds < 0 ? 0 : $seconds * NetworkParams::TICKS_PER_SECOND;
        if ($this->world !== null) {
            $this->world->consoleInfo('game', 'Warmup Seconds='.($this->warmup / NetworkParams::TICKS_PER_SECOND));
        }
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
        $this->teamScores    = [0, 0];
        $this->forceBalanced = false;
        if ($world !== null) {
            $world->consoleInfo('game', 'Round started Gametype='.$this->getGameType());
        }
    }

    public function resetMap(): void
    {
        parent::resetMap();

        $this->teamScores     = [0, 0];
        $this->flags          = [null, null];
        $this->roundStartTick = $this->tickHandler->get();
        $this->gameOverTick   = -1;
        $this->suddenDeath    = 0;
        $this->warmup         = 0;
        $this->unpauseTimer   = 0;
        $this->roundCount     = 0;
        $this->unbalancedTick = -1;
        $this->forceBalanced  = false;
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
        if ($world !== null && ! $this->isGameOver()) {
            $scores = $this->getTeamScores();
            $world->consoleInfo('game', 'Round ended Gametype='.$this->getGameType().($this->isTeamMode ? ' ScoreRed='.$scores[0].' ScoreBlue='.$scores[1] : ''));
        }
        $this->gameOverTick = $this->tickHandler->get();
        $this->suddenDeath  = 0;
    }

    public function cycleMap(): void {}

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

    public function clampTeam(int $team): int
    {
        return $this->isTeamMode && $team >= 0 ? $team & 1 : parent::clampTeam($team);
    }

    public function canChangeTeam(PlayerTee $tee, int $team): bool
    {
        $team = $this->clampTeam($team);
        if (! $this->isTeamMode || $team === GameConstants::TEAM_SPECTATORS || $this->teamBalanceTime === 0 || $this->world === null) {
            return true;
        }

        $counts = [0, 0];
        foreach ($this->world->getTees() as $player) {
            if ($player instanceof PlayerTee && $player !== $tee && $player->team !== GameConstants::TEAM_SPECTATORS) {
                $counts[$player->team]++;
            }
        }
        $counts[$team]++;

        return abs($counts[0] - $counts[1]) < 2 || $counts[$team] < $counts[$team ^ 1];
    }

    public function canBeMovedOnBalance(int $teeIndex): bool
    {
        if (! $this->isCaptureTheFlag) {
            return true;
        }

        $character = $this->world?->findCharacter($teeIndex);

        if ($character !== null) {
            foreach ($this->flags as $flag) {
                if ($flag?->carryingCharacter === $character) {
                    return false;
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

        $victimTee = $this->world->findTee($victimTeeIndex);
        $killerTee = $this->world->findTee($killerTeeIndex);

        if (! $victimTee instanceof PlayerTee || ! $killerTee instanceof PlayerTee) {
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

    public function checkTeamBalance(): bool
    {
        if (! $this->isTeamMode || $this->teamBalanceTime === 0) {
            return true;
        }

        $world = $this->world;
        if ($world === null) {
            return true;
        }

        $teamSizes = [0, 0];
        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }
            $teamSizes[$tee->team]++;
        }

        if (abs($teamSizes[0] - $teamSizes[1]) >= 2) {
            if ($this->unbalancedTick === -1) {
                $this->unbalancedTick = $this->tickHandler->get();
                $world->consoleDebug('game', 'Teams unbalanced Red='.$teamSizes[0].' Blue='.$teamSizes[1]);
            }

            return false;
        }

        if ($this->unbalancedTick !== -1) {
            $world->consoleDebug('game', 'Teams balanced Red='.$teamSizes[0].' Blue='.$teamSizes[1]);
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

        $teamSizes        = [0, 0];
        $teamScoreRates   = [0.0, 0.0];
        $playerScoreRates = [];

        foreach ($world->getTees() as $tee) {
            if (! $tee instanceof PlayerTee || $tee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }
            $teamSizes[$tee->team]++;
            $elapsed                          = $this->tickHandler->get() - $tee->scoreStartTick;
            $playerScoreRates[$tee->teeIndex] = $elapsed > 0
                ? $tee->score * NetworkParams::TICKS_PER_SECOND * 60.0 / $elapsed
                : 0.0;
            $teamScoreRates[$tee->team] += $playerScoreRates[$tee->teeIndex];
        }

        if (abs($teamSizes[0] - $teamSizes[1]) < 2) {
            return;
        }

        $largerTeam  = $teamSizes[0] > $teamSizes[1] ? GameConstants::TEAM_RED : GameConstants::TEAM_BLUE;
        $smallerTeam = $largerTeam ^ 1;

        for ($playersToMove = intdiv(abs($teamSizes[0] - $teamSizes[1]), 2); $playersToMove > 0; $playersToMove--) {
            $candidate           = null;
            $bestScoreDifference = $teamScoreRates[$largerTeam];

            foreach ($world->getTees() as $tee) {
                if (! $tee instanceof PlayerTee || $tee->team !== $largerTeam) {
                    continue;
                }
                if (! $this->canBeMovedOnBalance($tee->teeIndex)) {
                    continue;
                }

                $scoreDifference = abs(
                    ($teamScoreRates[$smallerTeam] + $playerScoreRates[$tee->teeIndex])
                    - ($teamScoreRates[$largerTeam] - $playerScoreRates[$tee->teeIndex])
                );
                if ($candidate === null || $scoreDifference < $bestScoreDifference) {
                    $candidate           = $tee;
                    $bestScoreDifference = $scoreDifference;
                }
            }

            if ($candidate === null) {
                break;
            }

            $lastActionTick = $candidate->lastActionTick;
            $candidate->setTeam($smallerTeam);
            $candidate->lastActionTick = $lastActionTick;
            $candidate->forceBalanced  = true;
            $world->consoleInfo('game', 'TeeIdx='.$candidate->teeIndex.' Name="'.$candidate->name.'" Auto-balanced to '.$this->getTeamName($smallerTeam));
            $teamScoreRates[$largerTeam] -= $playerScoreRates[$candidate->teeIndex];
            $teamScoreRates[$smallerTeam] += $playerScoreRates[$candidate->teeIndex];
        }

        $this->forceBalanced = true;
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

    /** @return int[] */
    protected function getSpawnTypes(int $team): array
    {
        if (! $this->isTeamMode) {
            return parent::getSpawnTypes($team);
        }

        $ownTeam   = 1 + ($team & 1);
        $enemyTeam = 1 + (($team + 1) & 1);

        return [$ownTeam, self::SPAWN_HUMAN, $enemyTeam];
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

        $world  = $this->world;
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
        if ($this->getFlag(GameConstants::TEAM_RED) !== null || $this->getFlag(GameConstants::TEAM_BLUE) !== null) {
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
            $teamScores = $this->getTeamScores();

            $snaps[] = new ObjGameDataItem(
                teamScoreRed: $teamScores[GameConstants::TEAM_RED],
                teamScoreBlue: $teamScores[GameConstants::TEAM_BLUE],
                flagCarrierRedIndex: $this->getFlagCarrierId(GameConstants::TEAM_RED),
                flagCarrierBlueIndex: $this->getFlagCarrierId(GameConstants::TEAM_BLUE)
            );
        }

        return $snaps;
    }

    /** @return array{int, int} */
    protected function getTeamScores(): array
    {
        return $this->teamScores;
    }

    protected function getFlagCarrierId(int $team): int
    {
        $flag = $this->getFlag($team);
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

    /** @return array<int, FlagEntity|null> */
    public function getFlags(): array
    {
        return $this->flags;
    }

    public function collectFlagPoints(GameLayer $gameLayer): void
    {
        $world = $this->world;
        if (! $this->isCaptureTheFlag || $world === null) {
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

            $flag               = new FlagEntity($world, new Vector2($entity['x'], $entity['y']), $team);
            $this->flags[$team] = $flag;
            $world->addEntity($flag);
        }
    }

    protected function tickFlags(): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        $collision   = $world->getMap()->getCollision();
        $currentTick = $this->tickHandler->get();

        for ($fi = 0; $fi < 2; $fi++) {
            $flag = $this->flags[$fi];
            if ($flag === null) {
                continue;
            }

            $flagPos = $flag->getPosition();
            if ($flag->gameLayerClipped($flagPos)
                || ($collision !== null && ($collision->getCollisionAt($flagPos->x, $flagPos->y) & Collision::COLFLAG_DEATH))
            ) {
                $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                $flag->reset();
                $world->consoleDebug('ctf', 'Flag returned Team='.$flag->team.' Reason=invalid position');

                continue;
            }

            if ($flag->carryingCharacter !== null) {
                $this->tickCarriedFlag($flag, $fi, $currentTick);
            } else {
                $this->tickDroppedOrAtStandFlag($flag, $collision, $currentTick);
            }
        }
    }

    protected function tickCarriedFlag(FlagEntity $flag, int $fi, int $currentTick): void
    {
        $carrier = $flag->carryingCharacter;
        if ($carrier === null || ! $carrier->alive) {
            $flag->carryingCharacter = null;
            $flag->dropTick          = $currentTick;
            if ($this->world !== null) {
                $this->world->consoleDebug('ctf', 'Flag dropped Team='.$flag->team.' Reason=carrier unavailable');
            }

            return;
        }

        $flag->setPosition(clone $carrier->getPosition());
        $enemyFlag = $this->flags[$fi ^ 1] ?? null;
        if ($enemyFlag === null || ! $enemyFlag->atStand) {
            return;
        }
        if ($flag->getPosition()->distance($enemyFlag->getPosition()) >= FlagEntity::PHYS_SIZE + AbstractCharacterEntity::PHYS_SIZE) {
            return;
        }

        $this->onFlagCapture($flag, $fi);
    }

    protected function onFlagCapture(FlagEntity $flag, int $fi): void
    {
        $team = $fi === GameConstants::TEAM_RED ? GameConstants::TEAM_BLUE : GameConstants::TEAM_RED;
        $this->teamScores[$team] += 100;
        $carrierTee = $flag->carryingCharacter?->tee;
        if ($carrierTee instanceof PlayerTee) {
            $carrierTee->score += 5;
        }

        if ($this->world !== null) {
            $this->world->consoleDebug('ctf', 'Flag captured Team='.$flag->team.' TeeIdx='.($carrierTee->teeIndex ?? -1).' Duration='.(($this->tickHandler->get() - $flag->grabTick) / NetworkParams::TICKS_PER_SECOND).'s');
        }
        $flag->reset();
        $this->getFlag($fi ^ 1)?->reset();
        $this->world?->createSoundGlobal(GameConstants::SOUND_CTF_CAPTURE);
    }

    protected function tickDroppedOrAtStandFlag(FlagEntity $flag, ?Collision $collision, int $currentTick): void
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
            if (! $playerTee instanceof PlayerTee || $playerTee->team === GameConstants::TEAM_SPECTATORS) {
                continue;
            }

            if ($collision !== null) {
                [$hitFlags] = $collision->intersectLine($flag->getPosition(), $entity->getPosition());
                if ($hitFlags !== 0) {
                    continue;
                }
            }
            if ($flag->getPosition()->distance($entity->getPosition()) >= FlagEntity::PHYS_SIZE + $entity->getHitBoxRadius()) {
                continue;
            }

            if ($playerTee->team === $flag->team) {
                if (! $flag->atStand) {
                    $playerTee->score += 1;
                    $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                    $flag->reset();
                    $world->consoleDebug('ctf', 'Flag returned Team='.$flag->team.' TeeIdx='.$playerTee->teeIndex);
                }
            } else {
                $this->onFlagGrab($flag, $entity, $currentTick);

                break;
            }
        }

        if ($flag->carryingCharacter === null && ! $flag->atStand) {
            if ($currentTick > $flag->dropTick + NetworkParams::TICKS_PER_SECOND * 30) {
                $world->createSoundGlobal(GameConstants::SOUND_CTF_RETURN);
                $flag->reset();
                $world->consoleDebug('ctf', 'Flag returned Team='.$flag->team.' Reason=timeout');
            } elseif ($collision !== null) {
                $flag->vel->y += $world->getTuneController()->gravity / 100.0;
                $collision->moveBox(
                    $flag->getPosition(),
                    $flag->vel,
                    new Vector2(FlagEntity::PHYS_SIZE, FlagEntity::PHYS_SIZE),
                    0.5,
                );
            }
        }
    }

    protected function onFlagGrab(FlagEntity $flag, AbstractCharacterEntity $carrier, int $currentTick): void
    {
        $carrierTee = $carrier->tee;
        if ($flag->atStand) {
            $team = $flag->team === GameConstants::TEAM_RED ? GameConstants::TEAM_BLUE : GameConstants::TEAM_RED;
            $this->teamScores[$team]++;
            $flag->grabTick = $currentTick;
        }

        $flag->atStand           = false;
        $flag->carryingCharacter = $carrier;
        $flag->setPosition(clone $carrier->getPosition());
        if ($carrierTee instanceof PlayerTee) {
            $carrierTee->score += 1;
        }

        $world = $this->world;
        if ($world === null) {
            return;
        }

        $world->consoleDebug('ctf', 'Flag grabbed Team='.$flag->team.' TeeIdx='.($carrierTee->teeIndex ?? -1));
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

    protected function dropFlagsOnDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        $world = $this->world;
        if ($world === null) {
            return 0;
        }

        $hadFlag = 0;
        for ($i = 0; $i < 2; $i++) {
            $flag = $this->flags[$i];
            if ($flag === null) {
                continue;
            }

            $killerCharacter = $world->findCharacter($killerTeeIndex);
            if ($killerCharacter !== null && $flag->carryingCharacter === $killerCharacter) {
                $hadFlag |= 2;
            }

            if ($flag->carryingCharacter === $victim) {
                $flag->setPosition(clone $victim->getPosition());
                $world->createSoundGlobal(GameConstants::SOUND_CTF_DROP);
                $flag->dropTick          = $this->tickHandler->get();
                $flag->carryingCharacter = null;
                $flag->vel               = new Vector2(0, 0);
                $world->consoleDebug('ctf', 'Flag dropped Team='.$flag->team.' TeeIdx='.($victim->tee->teeIndex ?? -1));

                $killerTee = $world->findTee($killerTeeIndex);
                $victimTee = $victim->tee;
                if ($killerTee instanceof PlayerTee && $victimTee instanceof PlayerTee
                    && $killerTee->team !== $victimTee->team
                ) {
                    $killerTee->score++;
                }
                $hadFlag |= 1;
            }
        }

        return $hadFlag;
    }
}
