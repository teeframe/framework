<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\ChangeMapCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\RawPayload;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

function createVoteWorld(Map $map, TickHandler $tickHandler): AbstractWorld
{
    return new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void
        {
            // Only run the vote tick, not the full world tick
            $this->getVoteController()->tick($this);
        }
    };
}

function setVoteTick(TickHandler $tickHandler, int $tick): void
{
    $ref  = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setValue($tickHandler, $tick);
}

test('ClVoteChunk encodes and decodes correctly', function () {
    $chunk = new ClVoteChunk(vote: 1);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClVoteChunk::make(new RawPayload($payload));

    expect($decoded->vote)->toBe(1);
});

test('ClCallVoteChunk encodes and decodes correctly', function () {
    $chunk = new ClCallVoteChunk(
        type: 'option',
        value: 'map dm1',
        reason: 'because',
    );

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClCallVoteChunk::make(new RawPayload($payload));

    expect($decoded->type)->toBe('option');
    expect($decoded->value)->toBe('map dm1');
    expect($decoded->reason)->toBe('because');
});

test('SvVoteSetChunk encodes and decodes correctly', function () {
    $chunk = new SvVoteSetChunk(
        timeout: 25,
        description: 'Change map to dm1',
        reason: 'No reason given',
    );

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = SvVoteSetChunk::make(new RawPayload($payload));

    expect($decoded->timeout)->toBe(25);
    expect($decoded->description)->toBe('Change map to dm1');
    expect($decoded->reason)->toBe('No reason given');
});

test('SvVoteStatusChunk encodes and decodes correctly', function () {
    $chunk = new SvVoteStatusChunk(yes: 3, no: 1, pass: 1, total: 5);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = SvVoteStatusChunk::make(new RawPayload($payload));

    expect($decoded->yes)->toBe(3);
    expect($decoded->no)->toBe(1);
    expect($decoded->pass)->toBe(1);
    expect($decoded->total)->toBe(5);
});

test('callVote option starts a vote and broadcasts SvVoteSet', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Voter';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Observer';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('Change map to dm1', 'change_map dm1');

    $world->onMessage($tee1, new ClCallVoteChunk(
        type: 'option',
        value: 'Change map to dm1',
        reason: '',
    ));

    // Should have sent SvVoteSet to both tees
    $voteSets = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvVoteSetChunk);
    expect($voteSets)->toHaveCount(2);

    $msg = reset($voteSets);
    assert($msg instanceof SvVoteSetChunk);
    expect($msg->timeout)->toBeGreaterThan(0);
    expect($msg->description)->toBe('Change map to dm1');
    expect($msg->reason)->toBe('No reason given');

    // The caller should have automatically voted yes
    expect($tee1->vote)->toBe(1);
    expect($tee1->votePos)->toBe(1);
});

test('running vote timeout reports remaining whole seconds independently of server uptime', function (int $elapsedTicks, int $expectedSeconds) {
    $clock = new TickHandler(100_000);
    $world = createVoteWorld(new Map, $clock);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $votes = $world->getVoteController();
    expect($votes->getRunningVote($world))->toBeNull();

    $votes->startVote($world, 'Example option', 'example', 'Example reason');
    setVoteTick($clock, 100_000 + $elapsedTicks);
    $running = $votes->getRunningVote($world);
    expect($running)->toBeInstanceOf(SvVoteSetChunk::class);
    assert($running instanceof SvVoteSetChunk);
    expect($running->timeout)->toBe($expectedSeconds);
    expect($running->description)->toBe('Example option');
    expect($running->reason)->toBe('Example reason');

    $votes->endVote($world);
    expect($votes->getRunningVote($world))->toBeNull();
})->with([
    'just started'             => [0, 25],
    'one tick elapsed'         => [1, 24],
    'one second elapsed'       => [50, 24],
    'partial second remaining' => [375, 17],
    'one second remaining'     => [1200, 1],
    'less than one second'     => [1201, 0],
    'last tick'                => [1249, 0],
    'deadline'                 => [1250, 0],
    'past deadline'            => [1251, 0],
    'long past deadline'       => [1350, 0],
]);

test('votes resolve at the Teeworlds 0.6 integer thresholds', function (int $total, int $vote, int $threshold) {
    $clock   = new TickHandler(100);
    $world   = createVoteWorld(new Map, $clock);
    $command = new class extends AbstractCommand
    {
        public int $executions = 0;

        public function getName(): string
        {
            return 'threshold_test';
        }

        public function getType(): int
        {
            return self::TYPE_VOTE;
        }

        public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
        {
            $this->executions++;
        }
    };
    $world->registerCommand($command);
    $players = [];
    for ($i = 0; $i < $total; $i++) {
        $player = new PlayerTee;
        $world->addTee($player);
        $players[] = $player;
    }
    $spectator = new PlayerTee;
    $world->addTee($spectator);
    $spectator->team = GameConstants::TEAM_SPECTATORS;
    $votes           = $world->getVoteController();
    $votes->addVoteOption('Threshold test', $command->getCommand());
    $world->onMessage($players[0], new ClCallVoteChunk('option', 'Threshold test', ''));
    $world->onMessage($spectator, new ClVoteChunk($vote));

    // The caller has already voted YES; all remaining votes use other players.
    $additionalVotes = $threshold - ($vote === 1 ? 1 : 0);
    for ($i = 1; $i < $additionalVotes; $i++) {
        $world->onMessage($players[$i], new ClVoteChunk($vote));
    }
    recordingServer($world)->clear();
    $world->doTick();
    expect($votes->isVoteRunning())->toBeTrue();
    expect($command->executions)->toBe(0);
    $status = $votes->getRunningVoteStatus($world);
    assert($status instanceof SvVoteStatusChunk);
    expect($status->total)->toBe($total);
    expect($status->yes)->toBe($vote === 1 ? $threshold - 1 : 1);
    expect($status->no)->toBe($vote === -1 ? $threshold - 1 : 0);

    $world->onMessage($players[$additionalVotes], new ClVoteChunk($vote));
    recordingServer($world)->clear();
    $world->doTick();
    expect($votes->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBe($vote === 1 ? 1 : 0);
    $messages = array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvChatChunk);
    expect($messages)->not->toBeEmpty();
    foreach ($messages as $message) {
        expect($message->text)->toBe($vote === 1 ? 'Vote passed' : 'Vote failed');
    }
    $ended = array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvVoteSetChunk);
    expect($ended)->toHaveCount($total + 1);
    foreach ($ended as $chunk) {
        expect($chunk->timeout)->toBe(0);
    }
})->with([
    'two players need two YES'    => [2, 1, 2],
    'three players need two YES'  => [3, 1, 2],
    'four players need three YES' => [4, 1, 3],
    'five players need three YES' => [5, 1, 3],
    'six players need four YES'   => [6, 1, 4],
    'seven players need four YES' => [7, 1, 4],
    'two players need one NO'     => [2, -1, 1],
    'three players need two NO'   => [3, -1, 2],
    'four players need two NO'    => [4, -1, 2],
    'five players need three NO'  => [5, -1, 3],
    'six players need three NO'   => [6, -1, 3],
    'seven players need four NO'  => [7, -1, 4],
]);

test('vote passes when majority votes yes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $tee3       = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));

    // tee2 votes yes (now 2/3 = majority)
    $world->onMessage($tee2, new ClVoteChunk(1));

    // Tick — should pass (2 yes >= 3/2+1 = 2)
    $world->doTick();

    // Vote should be closed
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    // Should have sent "Vote passed" chat
    $chats  = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $passed = array_filter($chats, fn ($c) => $c->text === 'Vote passed');
    expect($passed)->not->toBeEmpty();
});

test('vote fails when majority votes no', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $tee3       = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));

    // tee2 and tee3 vote no (2 no >= (3+1)/2 = 2)
    $world->onMessage($tee2, new ClVoteChunk(-1));
    $world->onMessage($tee3, new ClVoteChunk(-1));

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats  = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $failed = array_filter($chats, fn ($c) => $c->text === 'Vote failed');
    expect($failed)->not->toBeEmpty();
});

test('vote fails on timeout', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes, 1/2 is not a majority)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));

    // Advance past the vote duration (25 seconds = 1250 ticks)
    setVoteTick($tickHandler, 100 + 1251);
    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats  = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $failed = array_filter($chats, fn ($c) => $c->text === 'Vote failed');
    expect($failed)->not->toBeEmpty();
});

test('cannot call a vote while another is running', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->getVoteController()->addVoteOption('option1', 'command1');
    $world->getVoteController()->addVoteOption('option2', 'command2');

    // Start first vote
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));
    expect($world->getVoteController()->isVoteRunning())->toBeTrue();

    // Try to start a second vote
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option2', ''));

    // The second vote should not have started — description should still be option1
    $runningVote = $world->getVoteController()->getRunningVote($world);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('option1');
});

test('player cannot vote twice', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));

    // tee2 votes yes
    $world->onMessage($tee2, new ClVoteChunk(1));
    expect($tee2->vote)->toBe(1);
    expect($tee2->votePos)->toBe(2);

    // tee2 tries to vote again — should be ignored
    $world->onMessage($tee2, new ClVoteChunk(-1));
    expect($tee2->vote)->toBe(1);
    expect($tee2->votePos)->toBe(2);
});

test('vote option lookup is case insensitive', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->getVoteController()->addVoteOption('Change Map', 'change_map');

    // Call with different case
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'change map', ''));

    $runningVote = $world->getVoteController()->getRunningVote($world);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('Change Map');
});

test('calling a non-existent option is rejected', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->onMessage($tee1, new ClCallVoteChunk('option', 'nonexistent', ''));

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats    = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $rejected = array_filter($chats, fn ($c) => str_contains($c->text, "isn't an option"));
    expect($rejected)->not->toBeEmpty();
});

test('vote status is broadcast after a vote', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', ''));

    $tee3 = new PlayerTee;
    $world->addTee($tee3);

    // One NO is below the rejection threshold with three eligible players.
    $world->onMessage($tee2, new ClVoteChunk(-1));

    // Tick — should broadcast vote status (not yet decided: 1 yes, 1 no, total 3)
    $world->doTick();

    $statuses = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvVoteStatusChunk);
    expect($statuses)->not->toBeEmpty();

    $status = reset($statuses);
    assert($status instanceof SvVoteStatusChunk);
    expect($status->yes)->toBe(1);
    expect($status->no)->toBe(1);
    expect($status->pass)->toBe(1);
    expect($status->total)->toBe(3);
});

test('kick vote kicks the target when it passes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Victim';
    $world->addTee($tee2);

    $tee3       = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    // Start a kick vote against tee2
    $world->onMessage($tee1, new ClCallVoteChunk('kick', (string) $tee2->teeIndex, 'griefing'));

    // tee3 votes yes (2/3 = majority)
    $world->onMessage($tee3, new ClVoteChunk(1));

    $world->doTick();

    // tee2 should have been kicked
    expect(recordingServer($world)->kickedTees)->toHaveKey($tee2->teeIndex);
    expect(recordingServer($world)->kickedTees[$tee2->teeIndex])->toBe('Kicked by vote');
});

test('cannot kick yourself', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->onMessage($tee1, new ClCallVoteChunk('kick', (string) $tee1->teeIndex, ''));

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats   = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $blocked = array_filter($chats, fn ($c) => str_contains($c->text, "can't kick yourself"));
    expect($blocked)->not->toBeEmpty();
});

test('spectate vote kills the target character when it passes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Target';
    $world->addTee($tee2);

    $tee3       = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    // Spawn the target's character so we can verify it gets killed
    $world->doTick();
    // doTick in createVoteWorld only runs vote tick, so spawn manually
    $spawnPos  = new Vector2(100, 100);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee2);
    $world->addEntity($character);
    expect($tee2->character)->not->toBeNull();
    $character = $tee2->character;
    assert($character instanceof PvpCharacterEntity);
    expect($character->alive)->toBeTrue();

    // Start a spectate vote against tee2
    $world->onMessage($tee1, new ClCallVoteChunk('spectate', (string) $tee2->teeIndex, 'afk'));

    // tee3 votes yes (2/3 = majority)
    $world->onMessage($tee3, new ClVoteChunk(1));

    $world->doTick();

    // The target should now be a spectator with no character
    expect($tee2->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee2->character)->toBeNull();
});

test('ChangeMapCommand is vote-only', function () {
    $command = new ChangeMapCommand('dm1');

    expect($command->getType())->toBe(AbstractCommand::TYPE_VOTE);
    expect($command->getName())->toBe('change_map dm1');
    expect($command->getDescription())->toBe('Change map to dm1');
    expect($command->getCommand())->toBe('change_map dm1');
});

test('ChangeMapCommand executes via vote', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        throw new RuntimeException('Required map fixture is missing');
    }

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createVoteWorld($map, $tickHandler);

    // Register the ChangeMapCommand as a vote command
    $world->registerCommand(new ChangeMapCommand('dm1'));

    $tee1       = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2       = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    // Start the vote using the command string
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'change_map dm1', ''));

    // The vote description should match the command's description
    $runningVote = $world->getVoteController()->getRunningVote($world);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('Change map to dm1');

    // tee2 votes yes (2/2 = majority)
    $world->onMessage($tee2, new ClVoteChunk(1));

    $world->doTick();

    // Vote should have passed and the command executed
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats  = array_filter(recordingServer($world)->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $passed = array_filter($chats, fn ($c) => $c->text === 'Vote passed');
    expect($passed)->not->toBeEmpty();
});
