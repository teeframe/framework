<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteStatusChunk;

class MembershipVoteCommand extends AbstractCommand
{
    public int $executions = 0;

    public function getName(): string
    {
        return 'membership_test';
    }

    public function getType(): int
    {
        return self::TYPE_SERVER;
    }

    public function getParams(): string
    {
        return '';
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?Closure $output = null): void
    {
        $this->executions++;
    }
}

/** @return array{TestWorld, list<PlayerTee>, MembershipVoteCommand} */
function createVoteMembershipWorld(int $count = 3, string $type = 'option'): array
{
    $world   = new TestWorld(new TickHandler(100), new Map);
    $command = new MembershipVoteCommand;
    $world->registerCommand($command);
    $players = [];
    for ($i = 0; $i < $count; $i++) {
        $player = new PlayerTee;
        $world->addTee($player);
        $players[] = $player;
    }
    $world->getVoteController()->addVoteOption($command->getDescription(), $command->getCommand());
    $world->onMessage($players[0], new ClCallVoteChunk($type, $type === 'option' ? $command->getDescription() : '1', ''));
    $world->doTick();
    recordingServer($world)->clear();

    return [$world, $players, $command];
}

/** @return list<SvVoteStatusChunk> */
function membershipVoteStatuses(TestWorld $world): array
{
    return array_values(array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvVoteStatusChunk));
}

test('departures refresh vote status and can pass an existing yes majority without another ballot', function () {
    [$world, $players, $command] = createVoteMembershipWorld();
    $world->removeTee($players[2]);
    recordingServer($world)->clear();

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeTrue();
    expect($command->executions)->toBe(0);
    $statuses = membershipVoteStatuses($world);
    expect($statuses)->toHaveCount(2);
    foreach ($statuses as $status) {
        expect([$status->yes, $status->no, $status->pass, $status->total])->toBe([1, 0, 1, 2]);
    }

    $world->removeTee($players[1]);
    recordingServer($world)->clear();
    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBe(1);
    $chats = array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvChatChunk);
    expect(array_values(array_map(fn ($chunk) => $chunk->text, $chats)))->toBe(['Vote passed']);
});

test('departures can reject an existing vote without another no ballot', function () {
    [$world, $players, $command] = createVoteMembershipWorld(5);
    $world->onMessage($players[1], new ClVoteChunk(-1));
    $world->doTick();
    foreach (array_slice($players, 2) as $player) {
        $world->removeTee($player);
    }
    recordingServer($world)->clear();

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBe(0);
    $chats = array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvChatChunk);
    expect(array_values(array_map(fn ($chunk) => $chunk->text, $chats)))->toBe(['Vote failed', 'Vote failed']);
});

test('arrivals refresh the vote status once without requiring another ballot', function (bool $spectator) {
    [$world, , $command] = createVoteMembershipWorld();
    $newPlayer           = new PlayerTee;
    $world->addTee($newPlayer);
    if ($spectator) {
        $newPlayer->team = GameConstants::TEAM_SPECTATORS;
    }
    recordingServer($world)->clear();

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeTrue();
    expect($command->executions)->toBe(0);
    $statuses = membershipVoteStatuses($world);
    expect($statuses)->toHaveCount(4);
    foreach ($statuses as $status) {
        expect([$status->yes, $status->no, $status->pass, $status->total])->toBe($spectator ? [1, 0, 2, 3] : [1, 0, 3, 4]);
    }
    recordingServer($world)->clear();
    $world->doTick();
    expect(membershipVoteStatuses($world))->toBeEmpty();
})->with([false, true]);

test('a player joining before resolution changes the required yes majority', function () {
    [$world, $players, $command] = createVoteMembershipWorld();
    $world->onMessage($players[1], new ClVoteChunk(1));
    $world->addTee(new PlayerTee);
    recordingServer($world)->clear();

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeTrue();
    expect($command->executions)->toBe(0);
    $statuses = membershipVoteStatuses($world);
    expect($statuses)->toHaveCount(4);
    expect([$statuses[0]->yes, $statuses[0]->total])->toBe([2, 4]);
});

test('membership refreshes preserve target aborts and creator cancellations even when the index is reused', function (string $type, int $leavingIndex, string $message) {
    [$world, $players, $command] = createVoteMembershipWorld(type: $type);
    $world->removeTee($players[$leavingIndex]);
    $replacement = new PlayerTee;
    $world->addTee($replacement);
    expect($replacement->teeIndex)->toBe($leavingIndex);
    recordingServer($world)->clear();

    $world->doTick();

    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBe(0);
    expect(recordingServer($world)->kickedTees)->toBeEmpty();
    $chats = array_filter(recordingServer($world)->sentChunks, fn ($chunk) => $chunk instanceof SvChatChunk);
    expect(array_values(array_unique(array_map(fn ($chunk) => $chunk->text, $chats))))->toBe([$message]);
})->with([
    'kick target left'     => ['kick', 1, 'Vote aborted'],
    'spectate target left' => ['spectate', 1, 'Vote aborted'],
    'creator left'         => ['option', 0, 'Vote canceled'],
]);

test('vote membership updates stay scoped to their world', function () {
    [$world, $players] = createVoteMembershipWorld();
    [$otherWorld]      = createVoteMembershipWorld();
    $world->removeTee($players[2]);
    recordingServer($world)->clear();

    $world->doTick();
    $otherWorld->doTick();

    expect(membershipVoteStatuses($world))->toHaveCount(2);
    expect(membershipVoteStatuses($otherWorld))->toBeEmpty();
    expect($otherWorld->getVoteController()->isVoteRunning())->toBeTrue();
});
