<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;

class TeamTransitionTestController extends PvpGameController
{
    public int $balanceChecks = 0;

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
    $controller = new TeamTransitionTestController($clock, teamBalanceTime: $balanceTime, inactiveKickTime: $inactiveTime, spectatorSlots: $spectatorSlots);
    $controller->setGameMode($mode);
    $world = new TestPvpWorldWithController($clock, new Map, $controller, slotsLimit: $slotsLimit);

    return [$world, $clock, $controller];
}

test('player requests and forced transitions normalize teams through their game controller', function (string $mode, int $requested, int $expected, bool $request) {
    [$world] = createTeamTransitionWorld($mode);
    $tee     = new PlayerTee;
    $world->addTee($tee);

    if ($request) {
        $world->onMessage($tee, new ClSetTeamChunk($requested));
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
])->with(['player request' => true, 'forced transition' => false]);

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
    $spectator->lastSetTeam    = $clock->get();
    $spectator->teamChangeTick = $clock->get() + 1000;
    $spectator->setTeam(GameConstants::TEAM_BLUE);
    expect($spectator->team)->toBe(GameConstants::TEAM_BLUE);
    expect($spectator->spawning)->toBeTrue();
    expect($spectator->teamChangeTick)->toBe($clock->get() + 1000);
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
    $balanceChecks = $controller->balanceChecks;

    switch ($source) {
        case 'request':
            $world->onMessage($target, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));
            break;
        case 'forced':
            $target->setTeam(GameConstants::TEAM_SPECTATORS);
            break;
        case 'inactivity':
            for ($i = 0; $i < 3001; $i++) {
                $clock->next();
            }
            $caller->lastActionTick = $witness->lastActionTick = $clock->get();
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
    expect($target->respawnTick)->toBe($clock->get() + 25);
    expect($target->lastActionTick)->toBe($clock->get());
    expect($controller->balanceChecks)->toBe($balanceChecks + 1);
})->with(['request', 'forced', 'inactivity', 'vote']);

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
    expect($player->respawnTick)->toBe($clock->get() + 25);
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
        $tee->lastSetTeam = $clock->get();
    } else {
        $tee->teamChangeTick = $clock->get() + 1000;
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
