<?php

namespace TeeFrame\Game;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjGameDataItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

abstract class AbstractGameController implements SnapableObject, TickableObject
{
    // Spawn type indices mirror Teeworlds IGameController:
    //   0 = ENTITY_SPAWN (human/neutral)
    //   1 = ENTITY_SPAWN_RED
    //   2 = ENTITY_SPAWN_BLUE
    public const SPAWN_HUMAN = 0;
    public const SPAWN_RED   = 1;
    public const SPAWN_BLUE  = 2;

    /**
     * @var array<int, Vector2[]>
     */
    protected array $spawnPoints = [
        self::SPAWN_HUMAN => [],
        self::SPAWN_RED   => [],
        self::SPAWN_BLUE  => [],
    ];

    public function __construct(
        protected TickHandler $tickHandler,
        protected bool $isTeamMode = false,
    ) {
    }

    abstract public function doTick(): void;

    abstract function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int;

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

        // If no team spawns, fall back to human spawns
        if (empty($this->spawnPoints[self::SPAWN_RED])) {
            $this->spawnPoints[self::SPAWN_RED] = $this->spawnPoints[self::SPAWN_HUMAN];
        }
        if (empty($this->spawnPoints[self::SPAWN_BLUE])) {
            $this->spawnPoints[self::SPAWN_BLUE] = $this->spawnPoints[self::SPAWN_HUMAN];
        }
    }


    public function canSpawn(AbstractWorld $world, int $team): ?Vector2
    {
        // Spectators can't spawn
        if ($team < 0) {
            return null;
        }

        if ($this->isTeamMode) {
            // Teamplay: own team spawn first, then human, then enemy.
            // 1+(Team&1) maps TEAM_RED(0) → SPAWN_RED(1), TEAM_BLUE(1) → SPAWN_BLUE(2).
            $ownTeam     = 1 + ($team & 1);
            $enemyTeam   = 1 + (($team + 1) & 1);
            $spawnOrder  = [$ownTeam, self::SPAWN_HUMAN, $enemyTeam];
        } else {
            // Non-teamplay: human → red → blue
            $spawnOrder = [self::SPAWN_HUMAN, self::SPAWN_RED, self::SPAWN_BLUE];
        }

        foreach ($spawnOrder as $type) {
            $pos = $this->evaluateSpawnType($world, $type);
            if ($pos !== null) {
                return $pos;
            }
        }

        // Fallback: return first available spawn or null
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

            // Closer players reduce the score
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

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        $snaps = [];

        $snaps[] = new ObjGameInfoItem(
            gameFlags: 0,
            gameStateFlags: 0,
            roundStartTick: $this->tickHandler->get(),
            warmupTimer: 0,
            scoreLimit: 0,
            timeLimit: 0,
            roundNum: 0,
            roundCurrent: 1,
        );

        if ($this->isTeamMode) {
            $snaps[] = new ObjGameDataItem(
                teamScoreRed: 0,
                teamScoreBlue: 0,
                flagCarrierRedIndex: -1,
                flagCarrierBlueIndex: -1
            );
        }

        return $snaps;
    }
}
