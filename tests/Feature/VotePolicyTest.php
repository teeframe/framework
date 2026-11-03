<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Server\Config\WorldConfig;
use TeeFrame\Server\ConnectionSlot;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

class VotePolicyGameController extends TestGameController
{
    /** @var array<int, bool> */
    public array $eligibility = [];

    /** @var list<string> */
    public array $requiredReasons = [];

    /** @var list<PlayerTee> */
    public array $spectated = [];

    public function isVoteEligible(PlayerTee $tee): bool
    {
        return $this->eligibility[$tee->teeIndex] ?? parent::isVoteEligible($tee);
    }

    public function isVoteReasonRequired(string $type): bool
    {
        return in_array($type, $this->requiredReasons, true);
    }

    public function onVoteSpectate(PlayerTee $tee): void
    {
        $this->spectated[]                 = $tee;
        $this->eligibility[$tee->teeIndex] = false;
        parent::onVoteSpectate($tee);
        // The shared rejoin delay must take precedence over mod-specific team timing.
        $tee->teamChangeTick = $this->tickHandler->getCurrentTick();
        $this->world?->getVoteController()->requestUpdate();
    }
}

class VotePolicyRconServer extends RconTestServer
{
    protected function createWorld(WorldConfig $config): AbstractWorld
    {
        $world = new TestWorldWithController(
            $this->tickHandler,
            new Map(__DIR__.'/../'.$config->settings->getString('sv_world_map').'.map'),
            new VotePolicyGameController($this->tickHandler),
            $this,
            slotsLimit: $config->slotsLimit,
            config: $config->settings,
            spectatorSlots: $config->spectatorSlots,
        );
        $world->identifier = $config->identifier;

        return $world;
    }
}

/** @return array{TestWorldWithController, VotePolicyGameController, list<PlayerTee>, RconTestCommand} */
function createVotePolicyWorld(int $count = 4): array
{
    $clock      = new TickHandler(100);
    $controller = new VotePolicyGameController($clock);
    $world      = new TestWorldWithController($clock, new Map(__DIR__.'/../dm1.map'), $controller);
    $command    = new RconTestCommand(name: 'policy_test');
    $world->registerCommand($command);
    $world->getVoteController()->addVoteOption('Policy option', 'policy_test');
    $players = [];
    for ($i = 0; $i < $count; $i++) {
        $player = new PlayerTee;
        $world->addTee($player);
        $players[] = $player;
    }
    recordingServer($world)->clear();

    return [$world, $controller, $players, $command];
}

test('vote policy decides who can call votes independently of their network team', function (string $type, bool $eligible) {
    [$world, $controller, $players]             = createVotePolicyWorld();
    $caller                                     = $players[0];
    $caller->team                               = $eligible ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
    $controller->eligibility[$caller->teeIndex] = $eligible;

    $world->onMessage($caller, new ClCallVoteChunk($type, $type === 'option' ? 'Policy option' : '1', ''));

    expect($world->getVoteController()->isVoteRunning())->toBe($eligible);
    expect($caller->vote)->toBe($eligible ? 1 : 0);
    expect($caller->lastVoteCall)->toBe($eligible ? 100 : 0);
    expect($caller->lastVoteTry)->toBe(100);
    if (! $eligible) {
        $replies = recordingServer($world)->sentPairs;
        expect($replies)->toHaveCount(1);
        expect($replies[0]['teeIndex'])->toBe($caller->teeIndex);
        expect($replies[0]['chunk'])->toBeInstanceOf(SvChatChunk::class);
        assert($replies[0]['chunk'] instanceof SvChatChunk);
        expect($replies[0]['chunk']->text)->toBe("Spectators aren't allowed to start a vote.");
    }
})->with(['option', 'kick', 'spectate'])->with([false, true]);

test('vote policy governs ballots and counts without removing first-ballot protection', function () {
    [$world, $controller, $players]                 = createVotePolicyWorld();
    $players[0]->team                               = GameConstants::TEAM_SPECTATORS;
    $players[1]->team                               = GameConstants::TEAM_SPECTATORS;
    $controller->eligibility[$players[0]->teeIndex] = true;
    $controller->eligibility[$players[1]->teeIndex] = true;
    $controller->eligibility[$players[2]->teeIndex] = false;
    $world->onMessage($players[0], new ClCallVoteChunk('option', 'Policy option', ''));

    $world->onMessage($players[1], new ClVoteChunk(0));
    expect($players[1]->vote)->toBe(0);
    $world->onMessage($players[1], new ClVoteChunk(-1));
    $world->onMessage($players[1], new ClVoteChunk(1));
    $world->onMessage($players[2], new ClVoteChunk(1));
    expect($players[1]->vote)->toBe(-1);
    expect($players[2]->vote)->toBe(0);

    // A ballot cast before losing eligibility must no longer count either.
    $players[2]->vote = 1;
    $status           = $world->getVoteController()->getRunningVoteStatus($world);
    expect($status)->toBeInstanceOf(SvVoteStatusChunk::class);
    assert($status instanceof SvVoteStatusChunk);
    expect([$status->yes, $status->no, $status->pass, $status->total])->toBe([1, 1, 1, 3]);
});

test('policy eligibility changes refresh a vote majority without a team change or new ballot', function () {
    [$world, $controller, $players, $command] = createVotePolicyWorld();
    $votes                                    = $world->getVoteController();
    $world->onMessage($players[0], new ClCallVoteChunk('option', 'Policy option', ''));
    $world->onMessage($players[1], new ClVoteChunk(1));
    $votes->tick($world);
    expect($votes->isVoteRunning())->toBeTrue();
    expect($command->executions)->toBeEmpty();

    $controller->eligibility[$players[2]->teeIndex] = false;
    $votes->requestUpdate();
    $votes->tick($world);

    expect($players[2]->team)->toBe(GameConstants::TEAM_RED);
    expect($votes->isVoteRunning())->toBeFalse();
    expect($command->executions)->toHaveCount(1);
});

test('kick vote minimum players uses policy eligibility instead of network teams', function (bool $enoughPlayers) {
    [$world, $controller, $players] = createVotePolicyWorld(3);
    $world->setConfig('sv_world_vote_kick_min_players', 3);
    foreach ($players as $player) {
        $player->team                               = $enoughPlayers ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
        $controller->eligibility[$player->teeIndex] = true;
    }
    $controller->eligibility[$players[2]->teeIndex] = $enoughPlayers;
    recordingServer($world)->clear();

    $world->onMessage($players[0], new ClCallVoteChunk('kick', '1', ''));

    expect($world->getVoteController()->isVoteRunning())->toBe($enoughPlayers);
    if (! $enoughPlayers) {
        $reply = recordingServer($world)->sentChunks[0];
        assert($reply instanceof SvChatChunk);
        expect($reply->text)->toBe('Not enough players for a kick vote.');
    }
})->with([false, true]);

test('required reasons are checked after type normalization and truncation but before vote resolution', function (string $type, string $reason) {
    [$world, $controller, $players] = createVotePolicyWorld();
    $controller->requiredReasons    = ['option', 'kick', 'spectate'];

    $world->onMessage($players[0], new ClCallVoteChunk($type, 'invalid option or target', $reason));

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($players[0]->lastVoteCall)->toBe(0);
    expect($players[0]->lastVoteTry)->toBe(100);
    $replies = recordingServer($world)->sentPairs;
    expect($replies)->toHaveCount(1);
    expect($replies[0]['teeIndex'])->toBe($players[0]->teeIndex);
    assert($replies[0]['chunk'] instanceof SvChatChunk);
    expect($replies[0]['chunk']->text)->toBe('You must give a reason for this vote.');
})->with(['OPTION', 'KICK', 'SPECTATE'])->with(['', '   ', '               x']);

test('reason policy can require player vote reasons without affecting option votes', function (string $type, string $reason, bool $allowed) {
    [$world, $controller, $players] = createVotePolicyWorld();
    $controller->requiredReasons    = ['kick', 'spectate'];

    $world->onMessage($players[0], new ClCallVoteChunk($type, $type === 'option' ? 'Policy option' : '1', $reason));

    expect($world->getVoteController()->isVoteRunning())->toBe($allowed);
    if ($allowed) {
        $running = $world->getVoteController()->getRunningVote($world);
        assert($running instanceof SvVoteSetChunk);
        expect($running->reason)->toBe($reason === '' ? 'No reason given' : $reason);
    }
})->with([
    'option needs no reason'     => ['option', '', true],
    'kick requires a reason'     => ['kick', '', false],
    'spectate requires a reason' => ['spectate', '   ', false],
    'kick with reason'           => ['kick', 'griefing', true],
    'spectate with reason'       => ['spectate', 'afk', true],
]);

test('default vote policies retain team eligibility and optional reasons', function (string $type) {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $caller = new PlayerTee;
    $target = new PlayerTee;
    $world->addTee($caller);
    $world->addTee($target);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Example', 'example');
    $controller = $world->getGameController();

    expect($controller->isVoteEligible($caller))->toBeTrue();
    expect($controller->isVoteReasonRequired($type))->toBeFalse();
    $target->team = GameConstants::TEAM_SPECTATORS;
    expect($controller->isVoteEligible($target))->toBeFalse();

    $world->onMessage($caller, new ClCallVoteChunk($type, $type === 'option' ? 'Example' : '1', ''));
    $world->onMessage($target, new ClVoteChunk(-1));

    $running = $votes->getRunningVote($world);
    expect($running)->toBeInstanceOf(SvVoteSetChunk::class);
    assert($running instanceof SvVoteSetChunk);
    expect($running->reason)->toBe('No reason given');
    expect($target->vote)->toBe(0);
    $status = $votes->getRunningVoteStatus($world);
    assert($status instanceof SvVoteStatusChunk);
    expect([$status->yes, $status->no, $status->total])->toBe([1, 0, 1]);
})->with(['option', 'kick', 'spectate']);

test('spectate policy cleans up mod participation even for existing spectators and keeps the configured rejoin delay', function (bool $alreadySpectating) {
    [$world, $controller, $players] = createVotePolicyWorld(3);
    $target                         = $players[1];
    $world->setConfig('sv_world_vote_spectate_rejoin_delay', 2);
    $target->team                               = $alreadySpectating ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
    $controller->eligibility[$target->teeIndex] = true;
    $character                                  = null;
    if (! $alreadySpectating) {
        $character = new PvpCharacterEntity($world, new Vector2(100, 100));
        $character->spawn(new Vector2(100, 100), $target);
        $world->addEntity($character);
    }

    $world->onMessage($players[0], new ClCallVoteChunk('spectate', '1', 'afk'));
    $world->onMessage($players[2], new ClVoteChunk(1));
    $world->getVoteController()->tick($world);

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($controller->spectated)->toBe([$target]);
    expect($controller->isVoteEligible($target))->toBeFalse();
    expect($target->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($target->character)->toBeNull();
    expect($target->teamChangeTick)->toBe(100 + 2 * 60 * NetworkParams::TICKS_PER_SECOND);
    if ($character !== null) {
        expect($character->alive)->toBeFalse();
    }
})->with([false, true]);

test('custom vote policies preserve administrator kick protections at call and execution time', function (bool $authenticateBeforeCall) {
    $server     = new VotePolicyRconServer;
    $world      = $server->entryWorld;
    $target     = $server->addClient(address: '192.0.2.1');
    $caller     = $server->addClient(address: '192.0.2.2');
    $other      = $server->addClient(address: '192.0.2.3');
    $controller = $world->getGameController();
    assert($controller instanceof VotePolicyGameController);
    $caller->playerTee()->team                               = GameConstants::TEAM_SPECTATORS;
    $controller->eligibility[$caller->playerTee()->teeIndex] = true;
    if ($authenticateBeforeCall) {
        $server->send($target, new RconAuthChunk('', 'test-secret', false));
    }

    $server->send($caller, new ClCallVoteChunk('kick', (string) $target->playerTee()->teeIndex, 'griefing'));
    expect($world->getVoteController()->isVoteRunning())->toBe(! $authenticateBeforeCall);
    if (! $authenticateBeforeCall) {
        $server->send($target, new RconAuthChunk('', 'test-secret', false));
        $server->send($other, new ClVoteChunk(1));
        $server->socket($caller)->packets = [];
        $world->getVoteController()->tick($world);
    }

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($target->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($target->rcon()->authenticated)->toBeTrue();
    expect($server->getBanList()->getBan('192.0.2.1'))->toBeNull();
    $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($caller)->chunks(SvChatChunk::class));
    expect($messages)->toContain($authenticateBeforeCall
        ? "You can't kick an authenticated administrator"
        : 'Vote command failed: Authenticated administrators cannot be kicked by vote');
    expect($messages)->not->toContain('Vote passed');
})->with([false, true]);

test('RCON forced spectate cleans up an eligible network spectator and retains the shared rejoin delay and broadcast', function () {
    $server     = new VotePolicyRconServer(clock: new TickHandler(100));
    $world      = $server->entryWorld;
    $admin      = $server->addClient();
    $target     = $server->addClient(port: 8301);
    $tee        = $target->playerTee();
    $controller = $world->getGameController();
    assert($controller instanceof VotePolicyGameController);
    $tee->team                               = GameConstants::TEAM_SPECTATORS;
    $controller->eligibility[$tee->teeIndex] = true;
    $world->setConfig('sv_world_vote_spectate_rejoin_delay', 2);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets  = [];
    $server->socket($target)->packets = [];

    $server->send($admin, new RconCmdChunk('force_vote spectate '.$tee->teeIndex.' "Caught player cleanup"'));

    expect($controller->spectated)->toBe([$tee]);
    expect($controller->isVoteEligible($tee))->toBeFalse();
    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee->teamChangeTick)->toBe(100 + 2 * 60 * NetworkParams::TICKS_PER_SECOND);
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    foreach ([$admin, $target] as $client) {
        $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class));
        expect($messages)->toBe(["'{$tee->name}' was moved to spectators (Caught player cleanup)"]);
    }
});

test('RCON forced spectate rejects ineligible targets without invoking cleanup or changing their delay', function (bool $networkSpectator) {
    $server     = new VotePolicyRconServer;
    $world      = $server->entryWorld;
    $admin      = $server->addClient();
    $target     = $server->addClient(port: 8301);
    $tee        = $target->playerTee();
    $controller = $world->getGameController();
    assert($controller instanceof VotePolicyGameController);
    $tee->team           = $networkSpectator ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED;
    $tee->teamChangeTick = 12345;
    if (! $networkSpectator) {
        $controller->eligibility[$tee->teeIndex] = false;
    }
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets  = [];
    $server->socket($target)->packets = [];

    $server->send($admin, new RconCmdChunk('force_vote spectate '.$tee->teeIndex));

    expect($controller->spectated)->toBeEmpty();
    expect($controller->isVoteEligible($tee))->toBeFalse();
    expect($tee->team)->toBe($networkSpectator ? GameConstants::TEAM_SPECTATORS : GameConstants::TEAM_RED);
    expect($tee->teamChangeTick)->toBe(12345);
    $replies = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($admin)->chunks(RconLineChunk::class));
    expect($replies)->toContain('The player is already a spectator');
    expect($server->socket($admin)->chunks(SvChatChunk::class))->toBeEmpty();
    expect($server->socket($target)->chunks(SvChatChunk::class))->toBeEmpty();
})->with([false, true]);

test('custom forced spectate policies do not bypass RCON permissions through unauthenticated commands chat or votes', function (string $channel) {
    $server     = new VotePolicyRconServer;
    $world      = $server->entryWorld;
    $caller     = $server->addClient();
    $target     = $server->addClient(port: 8301);
    $tee        = $target->playerTee();
    $controller = $world->getGameController();
    assert($controller instanceof VotePolicyGameController);
    $tee->team                               = GameConstants::TEAM_SPECTATORS;
    $tee->teamChangeTick                     = 12345;
    $controller->eligibility[$tee->teeIndex] = true;
    $line                                    = 'force_vote spectate '.$tee->teeIndex;
    $world->getVoteController()->addVoteOption('Forced spectate', $line);
    $request = match ($channel) {
        'rcon'  => new RconCmdChunk($line),
        'chat'  => new ClSayChunk(false, '/'.$line),
        'vote'  => new ClCallVoteChunk('option', 'Forced spectate', ''),
        default => throw new InvalidArgumentException('Unknown test channel: '.$channel),
    };

    $server->send($caller, $request);
    if ($channel === 'vote') {
        $server->send($target, new ClVoteChunk(1));
        $world->getVoteController()->tick($world);
    }

    expect($caller->rcon()->authenticated)->toBeFalse();
    expect($controller->spectated)->toBeEmpty();
    expect($controller->isVoteEligible($tee))->toBeTrue();
    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee->teamChangeTick)->toBe(12345);
})->with(['rcon', 'chat', 'vote']);
