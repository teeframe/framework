<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\ChangeMapCommand;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\Vote\VoteEnforce;
use TeeFrame\Game\Vote\VoteOption;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;
use TeeFrame\Network\RawPayload;

$mapPath = __DIR__ . '/../dm1.map';
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
    $ref = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setAccessible(true);
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
        force: 0,
    );

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClCallVoteChunk::make(new RawPayload($payload));

    expect($decoded->type)->toBe('option');
    expect($decoded->value)->toBe('map dm1');
    expect($decoded->reason)->toBe('because');
    expect($decoded->force)->toBe(0);
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
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Voter';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Observer';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('Change map to dm1', 'change_map dm1');

    $world->onMessage($tee1, new ClCallVoteChunk(
        type: 'option',
        value: 'Change map to dm1',
        reason: '',
        force: 0,
    ));

    // Should have sent SvVoteSet to both tees
    $voteSets = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvVoteSetChunk);
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

test('vote passes when majority votes yes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $tee3 = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));

    // tee2 votes yes (now 2/3 = majority)
    $world->onMessage($tee2, new ClVoteChunk(1));

    // Tick — should pass (2 yes >= 3/2+1 = 2)
    $world->doTick();

    // Vote should be closed
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    // Should have sent "Vote passed" chat
    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $passed = array_filter($chats, fn ($c) => $c->text === 'Vote passed');
    expect($passed)->not->toBeEmpty();
});

test('vote fails when majority votes no', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $tee3 = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));

    // tee2 and tee3 vote no (2 no >= (3+1)/2 = 2)
    $world->onMessage($tee2, new ClVoteChunk(-1));
    $world->onMessage($tee3, new ClVoteChunk(-1));

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $failed = array_filter($chats, fn ($c) => $c->text === 'Vote failed');
    expect($failed)->not->toBeEmpty();
});

test('vote fails on timeout', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start the vote (caller auto-votes yes, 1/2 is not a majority)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));

    // Advance past the vote duration (25 seconds = 1250 ticks)
    setVoteTick($tickHandler, 100 + 1251);
    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $failed = array_filter($chats, fn ($c) => $c->text === 'Vote failed');
    expect($failed)->not->toBeEmpty();
});

test('cannot call a vote while another is running', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->getVoteController()->addVoteOption('option1', 'command1');
    $world->getVoteController()->addVoteOption('option2', 'command2');

    // Start first vote
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));
    expect($world->getVoteController()->isVoteRunning())->toBeTrue();

    // Try to start a second vote
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option2', '', 0));

    // The second vote should not have started — description should still be option1
    $runningVote = $world->getVoteController()->getRunningVote($tee1);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('option1');
});

test('player cannot vote twice', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));

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
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->getVoteController()->addVoteOption('Change Map', 'change_map');

    // Call with different case
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'change map', '', 0));

    $runningVote = $world->getVoteController()->getRunningVote($tee1);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('Change Map');
});

test('calling a non-existent option is rejected', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->onMessage($tee1, new ClCallVoteChunk('option', 'nonexistent', '', 0));

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $rejected = array_filter($chats, fn ($c) => str_contains($c->text, "isn't an option"));
    expect($rejected)->not->toBeEmpty();
});

test('vote status is broadcast after a vote', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    $world->getVoteController()->addVoteOption('option1', 'command1');

    // Start vote (caller auto-votes yes)
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'option1', '', 0));

    // tee2 votes no
    $world->onMessage($tee2, new ClVoteChunk(-1));

    // Tick — should broadcast vote status (not yet decided: 1 yes, 1 no, total 2)
    $world->doTick();

    $statuses = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvVoteStatusChunk);
    expect($statuses)->not->toBeEmpty();

    $status = reset($statuses);
    assert($status instanceof SvVoteStatusChunk);
    expect($status->yes)->toBe(1);
    expect($status->no)->toBe(1);
    expect($status->total)->toBe(2);
});

test('kick vote kicks the target when it passes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Victim';
    $world->addTee($tee2);

    $tee3 = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    // Start a kick vote against tee2
    $world->onMessage($tee1, new ClCallVoteChunk('kick', (string) $tee2->teeIndex, 'griefing', 0));

    // tee3 votes yes (2/3 = majority)
    $world->onMessage($tee3, new ClVoteChunk(1));

    $world->doTick();

    // tee2 should have been kicked
    expect($GLOBALS['mockGameServer']->kickedTees)->toHaveKey($tee2->teeIndex);
    expect($GLOBALS['mockGameServer']->kickedTees[$tee2->teeIndex])->toBe('Kicked by vote');
});

test('cannot kick yourself', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $world->onMessage($tee1, new ClCallVoteChunk('kick', (string) $tee1->teeIndex, '', 0));

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $blocked = array_filter($chats, fn ($c) => str_contains($c->text, "can't kick yourself"));
    expect($blocked)->not->toBeEmpty();
});

test('spectate vote kills the target character when it passes', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Target';
    $world->addTee($tee2);

    $tee3 = new PlayerTee;
    $tee3->name = 'Voter3';
    $world->addTee($tee3);

    // Spawn the target's character so we can verify it gets killed
    $world->doTick();
    // doTick in createVoteWorld only runs vote tick, so spawn manually
    $spawnPos = new \TeeFrame\Game\World\Vector2(100, 100);
    $character = new \TeeFrame\Game\Entities\Character\PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee2);
    $world->addEntity($character);
    expect($tee2->character)->not->toBeNull();
    $character = $tee2->character;
    assert($character instanceof \TeeFrame\Game\Entities\Character\PvpCharacterEntity);
    expect($character->alive)->toBeTrue();

    // Start a spectate vote against tee2
    $world->onMessage($tee1, new ClCallVoteChunk('spectate', (string) $tee2->teeIndex, 'afk', 0));

    // tee3 votes yes (2/3 = majority)
    $world->onMessage($tee3, new ClVoteChunk(1));

    $world->doTick();

    // The target should now be a spectator with no character
    expect($tee2->team)->toBe(\TeeFrame\Game\GameConstants::TEAM_SPECTATORS);
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
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createVoteWorld($map, $tickHandler);

    // Register the ChangeMapCommand as a vote command
    $world->registerCommand(new ChangeMapCommand('dm1'));

    $tee1 = new PlayerTee;
    $tee1->name = 'Caller';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Voter2';
    $world->addTee($tee2);

    // Start the vote using the command string
    $world->onMessage($tee1, new ClCallVoteChunk('option', 'change_map dm1', '', 0));

    // The vote description should match the command's description
    $runningVote = $world->getVoteController()->getRunningVote($tee1);
    assert($runningVote instanceof SvVoteSetChunk);
    expect($runningVote->description)->toBe('Change map to dm1');

    // tee2 votes yes (2/2 = majority)
    $world->onMessage($tee2, new ClVoteChunk(1));

    $world->doTick();

    // Vote should have passed and the command executed
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvChatChunk);
    $passed = array_filter($chats, fn ($c) => $c->text === 'Vote passed');
    expect($passed)->not->toBeEmpty();
});
