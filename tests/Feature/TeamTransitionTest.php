<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Server\Config\Config;

class TeamTransitionTestController extends PvpGameController
{
    public int $balanceChecks = 0;

    /** @var list<array{PlayerTee, int, int}> */
    public array $teamChanges = [];

    public function onTeamChanged(PlayerTee $tee, int $previousTeam): void
    {
        $this->teamChanges[] = [$tee, $previousTeam, $tee->team];
    }

    public function checkTeamBalance(): bool
    {
        $this->balanceChecks++;

        return parent::checkTeamBalance();
    }
}

/** @return array{TestPvpWorldWithController, TickHandler, TeamTransitionTestController} */
function createTeamTransitionWorld(string $mode = 'TDM', int $slotsLimit = 8, int $spectatorSlots = 0, int $balanceTime = 0, int $inactiveTime = 0): array
{
    $clock      = new TickHandler(100);
    $controller = new TeamTransitionTestController($clock);
    $world      = new TestPvpWorldWithController($clock, new Map, $controller, slotsLimit: $slotsLimit, config: new Config([
        'sv_world_game_type'             => $mode,
        'sv_world_pvp_team_balance_time' => $balanceTime,
        'sv_world_inactivity_timeout'    => $inactiveTime,
    ]), spectatorSlots: $spectatorSlots);

    return [$world, $clock, $controller];
}

test('player requests forced and silent transitions normalize teams through their game controller', function (string $mode, int $requested, int $expected, string $source) {
    [$world] = createTeamTransitionWorld($mode);
    $tee     = new PlayerTee;
    $world->addTee($tee);

    if ($source === 'request') {
        $world->onMessage($tee, new ClSetTeamChunk($requested));
    } elseif ($source === 'silent') {
        $tee->setTeamSilently($requested);
    } else {
        $tee->setTeam($requested);
    }

    expect($tee->team)->toBe($expected);
    expect($tee->spawning)->toBe($expected !== GameConstants::TEAM_SPECTATORS);
})->with([
    'DM has one playing team'                   => ['DM', 1, 0],
    'DM normalizes large team ids'              => ['DM', 99, 0],
    'DM normalizes negative ids to spectators'  => ['DM', -99, -1],
    'TDM uses the low team bit'                 => ['TDM', 99, 1],
    'TDM normalizes even ids to red'            => ['TDM', 2, 0],
    'TDM normalizes negative ids to spectators' => ['TDM', -99, -1],
    'CTF uses the same team normalization'      => ['CTF', 99, 1],
])->with(['request', 'forced', 'silent']);

test('initial team selection reserves spectator capacity without requiring a pre-set spectator team', function (string $mode) {
    [$world] = createTeamTransitionWorld($mode, slotsLimit: 2, spectatorSlots: 1);
    $first   = new PlayerTee;
    $second  = new PlayerTee;
    $world->addTee($first);
    $world->addTee($second);

    expect($first->team)->toBe(GameConstants::TEAM_RED);
    expect($first->spawning)->toBeTrue();
    expect($second->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($second->spawning)->toBeFalse();
})->with(['DM', 'TDM', 'CTF']);

test('player requests enforce reserved capacity while forced moves bypass request restrictions', function () {
    [$world, $clock] = createTeamTransitionWorld(slotsLimit: 2, spectatorSlots: 1);
    $world->addTee(new PlayerTee);
    $spectator = new PlayerTee;
    $world->addTee($spectator);
    recordingServer($world)->clear();

    $world->onMessage($spectator, new ClSetTeamChunk(GameConstants::TEAM_BLUE));
    expect($spectator->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($spectator->spawning)->toBeFalse();
    $messages = recordingServer($world)->sentChunks;
    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(SvBroadcastChunk::class);
    assert($messages[0] instanceof SvBroadcastChunk);
    expect($messages[0]->text)->toBe('Only 1 active players are allowed');

    $world->setPaused(true);
    $spectator->lastSetTeam    = $clock->getCurrentTick();
    $spectator->teamChangeTick = $clock->getCurrentTick() + 1000;
    $spectator->setTeam(GameConstants::TEAM_BLUE);
    expect($spectator->team)->toBe(GameConstants::TEAM_BLUE);
    expect($spectator->spawning)->toBeTrue();
    expect($spectator->teamChangeTick)->toBe($clock->getCurrentTick() + 1000);
});

test('already active players may switch teams when all world slots are occupied', function () {
    [$world] = createTeamTransitionWorld(slotsLimit: 2);
    $first   = new PlayerTee;
    $second  = new PlayerTee;
    $world->addTee($first);
    $world->addTee($second);

    $world->onMessage($first, new ClSetTeamChunk(GameConstants::TEAM_BLUE));

    expect($first->team)->toBe(GameConstants::TEAM_BLUE);
    expect($world->getTees())->toHaveCount(2);
});

test('normalized no-op requests and forced moves do not kill characters or notify other systems', function (bool $request) {
    [$world, $clock, $controller] = createTeamTransitionWorld('DM');
    $tee                          = new PlayerTee;
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $tee);
    $world->addEntity($character);
    $tee->spectatorId    = 42;
    $tee->lastActionTick = 17;
    $tee->respawnTick    = 29;
    $balanceChecks       = $controller->balanceChecks;
    $world->getVoteController()->startVote($world, 'Example', 'noop', 'reason');
    $world->getVoteController()->tick($world);
    recordingServer($world)->clear();

    if ($request) {
        $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_BLUE));
    } else {
        $tee->setTeam(GameConstants::TEAM_BLUE);
    }
    $world->getVoteController()->tick($world);

    expect($tee->character)->toBe($character);
    expect($character->alive)->toBeTrue();
    expect($tee->spectatorId)->toBe(42);
    expect($tee->lastActionTick)->toBe(17);
    expect($tee->respawnTick)->toBe(29);
    expect($tee->lastSetTeam)->toBe(0);
    expect($controller->balanceChecks)->toBe($balanceChecks);
    expect($controller->teamChanges)->toBeEmpty();
    expect(recordingServer($world)->sentChunks)->toBeEmpty();
})->with([true, false]);

test('all spectator transition paths share character cleanup respawn state and follower release', function (string $source) {
    [$world, $clock, $controller] = createTeamTransitionWorld(inactiveTime: 1);
    $target                       = new PlayerTee;
    $caller                       = new PlayerTee;
    $witness                      = new PlayerTee;
    $spectator                    = new PlayerTee;
    foreach ([$target, $caller, $witness, $spectator] as $tee) {
        $world->addTee($tee);
    }
    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);
    $spectator->spectatorId = $target->teeIndex;
    $character              = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $target);
    $world->addEntity($character);
    $balanceChecks           = $controller->balanceChecks;
    $controller->teamChanges = [];

    switch ($source) {
        case 'request':
            $world->onMessage($target, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));
            break;
        case 'forced':
            $target->setTeam(GameConstants::TEAM_SPECTATORS);
            break;
        case 'inactivity':
            for ($i = 0; $i < 3001; $i++) {
                $clock->advanceTick();
            }
            $caller->lastActionTick = $witness->lastActionTick = $clock->getCurrentTick();
            $controller->doTick();
            break;
        case 'vote':
            $votes = $world->getVoteController();
            $votes->callVote($world, $caller, 'spectate', (string) $target->teeIndex, 'reason');
            $votes->vote($world, $witness, 1);
            $votes->tick($world);
            break;
    }

    expect($target->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($target->character)->toBeNull();
    expect($character->alive)->toBeFalse();
    expect($target->spawning)->toBeFalse();
    expect($target->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($spectator->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($target->respawnTick)->toBe($clock->getCurrentTick() + 25);
    expect($target->lastActionTick)->toBe($clock->getCurrentTick());
    expect($controller->balanceChecks)->toBe($balanceChecks + 1);
    expect($controller->teamChanges)->toBe([[$target, GameConstants::TEAM_RED, GameConstants::TEAM_SPECTATORS]]);
})->with(['request', 'forced', 'inactivity', 'vote']);

test('silent transitions share network bookkeeping without deaths announcements or gameplay policy callbacks', function (int $team) {
    [$world, $clock, $controller] = createTeamTransitionWorld();
    $player                       = new PlayerTee;
    $other                        = new PlayerTee;
    $follower                     = new PlayerTee;
    foreach ([$player, $other, $follower] as $tee) {
        $world->addTee($tee);
    }
    $follower->setTeam(GameConstants::TEAM_SPECTATORS);
    $follower->spectatorId = $player->teeIndex;
    $character             = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $player);
    $world->addEntity($character);
    $player->spectatorId     = 42;
    $player->lastActionTick  = 17;
    $player->respawnTick     = 29;
    $player->dieTick         = 31;
    $player->teamChangeTick  = 47;
    $balanceChecks           = $controller->balanceChecks;
    $controller->teamChanges = [];
    $votes                   = $world->getVoteController();
    $votes->startVote($world, 'Example', 'noop', 'reason');
    $votes->tick($world);
    recordingServer($world)->clear();

    $player->setTeamSilently($team);

    expect($player->team)->toBe($team);
    expect($player->character)->toBe($character);
    expect($character->alive)->toBeTrue();
    expect($character->isMarkedForDestruction())->toBeFalse();
    expect($player->spawning)->toBe($team !== GameConstants::TEAM_SPECTATORS);
    expect($player->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($follower->spectatorId)->toBe($team === GameConstants::TEAM_SPECTATORS ? GameConstants::SPEC_FREEVIEW : $player->teeIndex);
    expect($player->respawnTick)->toBe($clock->getCurrentTick() + 25);
    expect($player->lastActionTick)->toBe($clock->getCurrentTick());
    expect([$player->dieTick, $player->teamChangeTick, $player->lastSetTeam])->toBe([31, 47, 0]);
    expect($controller->teamChanges)->toBeEmpty();
    expect($controller->balanceChecks)->toBe($balanceChecks);
    expect(recordingServer($world)->sentChunks)->toBeEmpty();

    $votes->tick($world);
    $statuses = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvVoteStatusChunk));
    expect($statuses)->toHaveCount(3);
    expect($statuses[0]->total)->toBe($team === GameConstants::TEAM_SPECTATORS ? 1 : 2);
    recordingServer($world)->clear();

    $player->spectatorId = 42;
    $player->spawning    = ! $player->spawning;
    $clock->advanceTick();
    $player->setTeamSilently($team);
    $votes->tick($world);
    expect($player->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($player->spawning)->toBe($team !== GameConstants::TEAM_SPECTATORS);
    expect($player->respawnTick)->toBe(125);
    expect($player->lastActionTick)->toBe(100);
    expect($controller->teamChanges)->toBeEmpty();
    expect(recordingServer($world)->sentChunks)->toBeEmpty();
})->with([GameConstants::TEAM_BLUE, GameConstants::TEAM_SPECTATORS]);

test('a death callback can silently capture a player without the death tail re-enabling spawning', function () {
    $clock      = new TickHandler(100);
    $controller = new class($clock) extends TeamTransitionTestController
    {
        public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex, int $weapon): int
        {
            if ($victim->tee instanceof PlayerTee) {
                $victim->tee->setTeamSilently(GameConstants::TEAM_SPECTATORS);
            }

            return parent::onCharacterDeath($victim, $killerTeeIndex, $weapon);
        }
    };
    $world  = new TestWorldWithController($clock, new Map, $controller);
    $player = new PlayerTee;
    $world->addTee($player);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $player);
    $world->addEntity($character);

    $character->die($player->teeIndex, GameConstants::WEAPON_GAME);

    expect($player->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($player->spawning)->toBeFalse();
    expect($player->character)->toBeNull();
    expect($player->respawnTick)->toBe(125);
    expect($controller->teamChanges)->toBeEmpty();
});

test('silent transitions normalize teams even before a player is attached to a world', function () {
    $player = new PlayerTee;
    $player->setTeamSilently(99);
    expect($player->team)->toBe(GameConstants::TEAM_BLUE);
    expect($player->spawning)->toBeTrue();
    $player->setTeamSilently(-99);
    expect($player->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($player->spawning)->toBeFalse();
});

test('forced spectator transitions refresh an active vote and returning players request a respawn', function () {
    [$world, $clock] = createTeamTransitionWorld();
    $player          = new PlayerTee;
    $world->addTee($player);
    $world->addTee(new PlayerTee);
    $world->addTee(new PlayerTee);
    $votes = $world->getVoteController();
    $votes->startVote($world, 'Example', 'noop', 'reason');
    $votes->tick($world);
    recordingServer($world)->clear();

    $player->setTeam(GameConstants::TEAM_SPECTATORS);
    $votes->tick($world);
    $statuses = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvVoteStatusChunk));
    expect($statuses)->toHaveCount(3);
    expect($statuses[0]->total)->toBe(2);
    recordingServer($world)->clear();

    $player->setTeam(GameConstants::TEAM_BLUE);
    $votes->tick($world);
    $statuses = array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvVoteStatusChunk));
    expect($statuses)->toHaveCount(3);
    expect($statuses[0]->total)->toBe(3);
    expect($player->spawning)->toBeTrue();
    expect($player->respawnTick)->toBe($clock->getCurrentTick() + 25);
});

test('spectator follower cleanup never affects a different world with the same local tee index', function () {
    [$world]      = createTeamTransitionWorld();
    [$otherWorld] = createTeamTransitionWorld();
    $target       = new PlayerTee;
    $local        = new PlayerTee;
    $other        = new PlayerTee;
    $world->addTee($target);
    $world->addTee($local);
    $otherWorld->addTee(new PlayerTee);
    $otherWorld->addTee($other);
    $local->setTeam(GameConstants::TEAM_SPECTATORS);
    $other->setTeam(GameConstants::TEAM_SPECTATORS);
    $local->spectatorId = $other->spectatorId = $target->teeIndex;

    $target->setTeam(GameConstants::TEAM_SPECTATORS);

    expect($local->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($other->spectatorId)->toBe(0);
});

test('player-request cooldowns and pause restrictions do not apply to forced transitions', function (string $restriction) {
    [$world, $clock] = createTeamTransitionWorld();
    $tee             = new PlayerTee;
    $world->addTee($tee);
    if ($restriction === 'paused') {
        $world->setPaused(true);
    } elseif ($restriction === 'spam cooldown') {
        $tee->lastSetTeam = $clock->getCurrentTick();
    } else {
        $tee->teamChangeTick = $clock->getCurrentTick() + 1000;
    }

    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));
    expect($tee->team)->toBe(GameConstants::TEAM_RED);
    $tee->setTeam(GameConstants::TEAM_SPECTATORS);
    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
})->with(['paused', 'spam cooldown', 'team-change delay']);

test('voluntary team switches respect enabled PvP balance rules but forced balancing can still move players', function () {
    [$world] = createTeamTransitionWorld(balanceTime: 1);
    $red     = new PlayerTee;
    $blue    = new PlayerTee;
    $world->addTee($red);
    $world->addTee($blue);
    recordingServer($world)->clear();

    $world->onMessage($red, new ClSetTeamChunk(GameConstants::TEAM_BLUE));
    expect($red->team)->toBe(GameConstants::TEAM_RED);
    $messages = recordingServer($world)->sentChunks;
    expect($messages)->toHaveCount(1);
    assert($messages[0] instanceof SvBroadcastChunk);
    expect($messages[0]->text)->toBe('Teams must be balanced, please join other team');

    $red->setTeam(GameConstants::TEAM_BLUE);
    expect($red->team)->toBe(GameConstants::TEAM_BLUE);
});

test('players can join the smaller team even when the resulting teams remain unbalanced', function () {
    [$world] = createTeamTransitionWorld(balanceTime: 1);
    $players = [];
    for ($i = 0; $i < 6; $i++) {
        $tee = new PlayerTee;
        $world->addTee($tee);
        $players[] = $tee;
    }
    foreach (array_slice($players, 0, 5) as $tee) {
        $tee->setTeam(GameConstants::TEAM_RED);
    }

    $world->onMessage($players[0], new ClSetTeamChunk(GameConstants::TEAM_BLUE));

    expect($players[0]->team)->toBe(GameConstants::TEAM_BLUE);
});

test('custom game controllers own team normalization and player-request admission policy', function () {
    $clock      = new TickHandler(100);
    $controller = new class($clock) extends AbstractGameController
    {
        public function getGameType(): string
        {
            return 'Custom';
        }

        public function clampTeam(int $team): int
        {
            return max(-1, min(7, $team));
        }

        public function canChangeTeam(PlayerTee $tee, int $team): bool
        {
            return $team !== 7;
        }
    };
    $world = new TestWorldWithController($clock, new Map, $controller);
    $tee   = new PlayerTee;
    $world->addTee($tee);

    $world->onMessage($tee, new ClSetTeamChunk(99));
    expect($tee->team)->toBe(0);
    $tee->setTeam(99);
    expect($tee->team)->toBe(7);
});

test('PvP balance settings leave an asymmetric custom controller policy untouched', function () {
    $clock      = new TickHandler(100);
    $controller = new class($clock) extends AbstractGameController
    {
        public int $balanceChecks = 0;

        public function getGameType(): string
        {
            return 'Asymmetric';
        }

        public function clampTeam(int $team): int
        {
            return $team < 0 ? GameConstants::TEAM_SPECTATORS : $team & 1;
        }

        public function getAutoTeam(int $notThisTeeIndex): int
        {
            return array_any($this->getWorld()?->getTees() ?? [], fn (AbstractTee $tee): bool => $tee instanceof PlayerTee && $tee->team === GameConstants::TEAM_RED)
                ? GameConstants::TEAM_BLUE
                : GameConstants::TEAM_RED;
        }

        public function checkTeamBalance(): bool
        {
            $this->balanceChecks++;

            return true;
        }
    };
    $world   = new TestWorldWithController($clock, new Map, $controller);
    $players = [];

    foreach (range(1, 5) as $_) {
        $player = new PlayerTee;
        $world->addTee($player);
        $players[] = $player;
    }

    $world->setConfig('sv_world_pvp_team_balance_time', 1);
    $world->doTick();

    expect($controller->balanceChecks)->toBe(0);
    expect(array_map(fn (PlayerTee $tee): int => $tee->team, $players))->toBe([0, 1, 1, 1, 1]);
});
