<?php

namespace TeeFrame\Game;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Core\TickableObject;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;

abstract class AbstractGameController implements SnapableObject, TickableObject
{
    public const SPAWN_HUMAN                = 0;
    public const SPAWN_RED                  = 1;
    public const SPAWN_BLUE                 = 2;
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

    protected int $mapStartTick;

    protected ?AbstractWorld $world = null;

    public function __construct(
        protected TickHandler $tickHandler,
        protected int $inactiveKickTime = 0,
        protected int $inactiveKick = self::INACTIVE_KICK_TO_SPECTATOR,
        protected int $spectatorSlots = 0,
    ) {
        $this->mapStartTick = $tickHandler->get();
    }

    public function setWorld(AbstractWorld $world): void
    {
        $this->world = $world;
    }

    public function getWorld(): ?AbstractWorld
    {
        return $this->world;
    }

    abstract public function getGameType(): string;

    public function setGameMode(string $gameMode): void
    {
        throw new \RuntimeException('Unsupported gamemode: '.$gameMode);
    }

    public function getSpectatorSlots(): int
    {
        return $this->spectatorSlots;
    }

    public function bootMap(GameLayer $gameLayer): void
    {
        $this->collectSpawnPoints($gameLayer);
    }

    public function resetMap(): void
    {
        $this->spawnPoints = [
            self::SPAWN_HUMAN => [],
            self::SPAWN_RED   => [],
            self::SPAWN_BLUE  => [],
        ];

        $this->mapStartTick = $this->tickHandler->get();
    }

    public function doTick(): void
    {
        if ($this->inactiveKickTime > 0 && $this->world !== null) {
            $this->checkInactivePlayers();
        }
    }

    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        return 0;
    }

    public function isTeamplay(): bool
    {
        return false;
    }

    public function isFriendlyFire(int $victimTeeIndex, int $killerTeeIndex): bool
    {
        return false;
    }

    public function getAutoTeam(int $notThisTeeIndex): int
    {
        return $this->canJoinTeam(GameConstants::TEAM_RED, $notThisTeeIndex)
            ? GameConstants::TEAM_RED
            : GameConstants::TEAM_SPECTATORS;
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

        $tee = $world->findTee($notThisTeeIndex);
        if ($tee instanceof PlayerTee && $tee->team !== GameConstants::TEAM_SPECTATORS) {
            return true;
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

        return $numPlayers < $world->getSlotsLimit() - $this->spectatorSlots;
    }

    public function checkTeamBalance(): bool
    {
        return true;
    }

    public function getTeamName(int $team): string
    {
        return $team === GameConstants::TEAM_SPECTATORS ? 'spectators' : 'game';
    }

    protected function checkInactivePlayers(): void
    {
        $world = $this->world;
        if ($world === null) {
            return;
        }

        $threshold   = $this->inactiveKickTime * NetworkParams::TICKS_PER_SECOND * 60;
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

        $spawnOrder = $this->getSpawnTypes($team);

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

    /** @return int[] */
    protected function getSpawnTypes(int $team): array
    {
        return [self::SPAWN_HUMAN, self::SPAWN_RED, self::SPAWN_BLUE];
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

    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array
    {
        return [new ObjGameInfoItem(
            gameFlags: 0,
            gameStateFlags: $this->world?->isPaused() ? GameConstants::GAMESTATEFLAG_PAUSED : 0,
            roundStartTick: $this->mapStartTick,
            warmupTimer: 0,
            scoreLimit: 0,
            timeLimit: 0,
            roundNum: 0,
            roundCurrent: 1,
        )];
    }
}
