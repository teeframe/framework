<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\ConnectionHandler;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

class WorldHelpersTestSocket extends AbstractSocket
{
    /** @var list<array{port: int, data: string}> */
    public array $sent = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_int($port) && is_string($send_data));
        $this->sent[] = ['port' => $port, 'data' => $send_data];

        return true;
    }

    /** @return list<AbstractPacket> */
    public function packets(): array
    {
        $packets = [];
        foreach ($this->sent as $sent) {
            $packet = PacketDecoder::decodeFromRaw($sent['data']);
            assert($packet instanceof AbstractPacket);
            $packets[] = $packet;
        }

        return $packets;
    }

    /** @return list<string> */
    public function chatTexts(): array
    {
        $texts = [];
        foreach ($this->packets() as $packet) {
            if (! $packet instanceof DefaultPacket) {
                continue;
            }

            foreach ($packet->getChunks() as $chunk) {
                if ($chunk instanceof SvChatChunk) {
                    $texts[] = $chunk->text;
                }
            }
        }

        return $texts;
    }
}

class WorldHelpersTestServer extends AbstractServerInstance
{
    public WorldHelpersTestSocket $socket;

    public function __construct()
    {
        parent::__construct(new TickHandler(100), name: 'World helpers');
        $this->socket = new WorldHelpersTestSocket;
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->worlds[0];
    }

    public function addWorld(): TestWorld
    {
        $world          = new TestWorld($this->tickHandler, new Map, $this);
        $this->worlds[] = $world;

        return $world;
    }

    public function addClient(AbstractWorld $world, int $port): ConnectionSlot
    {
        $slot = $this->connectionHandler->startNew($this->socket, $world);
        assert($slot instanceof ConnectionSlot);
        $slot->init('127.0.0.1', $port);
        $slot->state = ConnectionSlot::STATE_INGAME;
        $world->addTee($slot->playerTee());

        return $slot;
    }

    public function connections(): ConnectionHandler
    {
        return $this->connectionHandler;
    }
}

class WorldHelpersTestVoteCommand extends AbstractCommand
{
    /** @var list<array{world: AbstractWorld, tee: AbstractTee, matches: string[]}> */
    public array $executions = [];

    public function __construct(
        protected string $name = 'record_vote',
        protected string $description = 'Recorded vote',
        protected int $type = self::TYPE_VOTE,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        $this->executions[] = ['world' => $world, 'tee' => $tee, 'matches' => $matches];
    }
}

test('empty worlds have no lookup results or broadcast recipients', function () {
    $server = new WorldHelpersTestServer;
    $world  = $server->addWorld();

    expect($world->findTee(0))->toBeNull();
    expect($world->findTee(-1))->toBeNull();
    expect($world->findCharacter(0))->toBeNull();
    expect($server->connections()->findByTee($world, 0))->toBeNull();

    $world->broadcast(new SvChatChunk(0, -1, 'Empty world'));
    expect($server->socket->sent)->toBeEmpty();
});

test('tee lookups remain world scoped across sparse and reused indexes', function () {
    $server      = new WorldHelpersTestServer;
    $firstWorld  = $server->addWorld();
    $secondWorld = $server->addWorld();
    $first       = $server->addClient($firstWorld, 8300);
    $remaining   = $server->addClient($firstWorld, 8301);
    $other       = $server->addClient($secondWorld, 8400);

    expect($firstWorld->findTee(0))->toBe($first->playerTee());
    expect($secondWorld->findTee(0))->toBe($other->playerTee());

    $first->closeConnection('Leaving');
    expect($firstWorld->findTee(0))->toBeNull();
    expect($firstWorld->findTee(1))->toBe($remaining->playerTee());
    expect($secondWorld->findTee(0))->toBe($other->playerTee());

    $replacement = $server->addClient($firstWorld, 8302);
    expect($replacement->playerTee()->teeIndex)->toBe(0);
    expect($firstWorld->findTee(0))->toBe($replacement->playerTee());
});

test('character lookups only return live characters present in their own world', function () {
    $server      = new WorldHelpersTestServer;
    $firstWorld  = $server->addWorld();
    $secondWorld = $server->addWorld();
    $first       = $server->addClient($firstWorld, 8300);
    $second      = $server->addClient($secondWorld, 8400);
    $position    = new Vector2(100, 100);
    $character   = new PvpCharacterEntity($firstWorld, clone $position);
    $character->spawn($position, $first->playerTee());
    $firstWorld->addEntity($character);
    $otherCharacter = new PvpCharacterEntity($secondWorld, clone $position);
    $otherCharacter->spawn($position, $second->playerTee());
    $secondWorld->addEntity($otherCharacter);

    expect($firstWorld->findCharacter(0))->toBe($character);
    expect($secondWorld->findCharacter(0))->toBe($otherCharacter);
    expect($firstWorld->findCharacter(1))->toBeNull();

    $character->alive = false;
    expect($firstWorld->findCharacter(0))->toBeNull();
    expect($secondWorld->findCharacter(0))->toBe($otherCharacter);

    $character->alive = true;
    $firstWorld->removeEntity($character);
    expect($firstWorld->findCharacter(0))->toBeNull();
});

test('broadcasts stay in their world and isolate each connection sequence and resend state', function () {
    $server     = new WorldHelpersTestServer;
    $world      = $server->addWorld();
    $otherWorld = $server->addWorld();
    $first      = $server->addClient($world, 8300);
    $second     = $server->addClient($world, 8301);
    $server->addClient($otherWorld, 8400);
    $first->chunks()->reset();
    $second->chunks()->reset();
    $first->sequence      = 7;
    $second->sequence     = 30;
    $server->socket->sent = [];
    $chunk                = new SvChatChunk(0, -1, 'World message');

    $world->broadcast($chunk);
    expect(array_column($server->socket->sent, 'port'))->toBe([8300, 8301]);
    expect($chunk->getSequence())->toBe(-1);

    $first->chunks()->resend();
    $second->chunks()->resend();

    $packets = $server->socket->packets();
    expect($packets)->toHaveCount(4);
    foreach ([8, 31, 8, 31] as $index => $sequence) {
        $packet = $packets[$index];
        expect($packet)->toBeInstanceOf(DefaultPacket::class);
        assert($packet instanceof DefaultPacket);
        expect($packet->getChunks())->toHaveCount(1);
        expect($packet->getChunks()[0]->getSequence())->toBe($sequence);
    }

    expect($chunk->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL);
});

test('connection lookups and directed sends exclude non-gameplay states without crossing worlds', function (int $state) {
    $server     = new WorldHelpersTestServer;
    $world      = $server->addWorld();
    $otherWorld = $server->addWorld();
    $other      = $server->addClient($otherWorld, 8400);
    $local      = $server->addClient($world, 8300);

    expect($server->connections()->findByTee($world, 0))->toBe($local);
    expect($server->connections()->findByTee($otherWorld, 0))->toBe($other);

    $local->state         = $state;
    $server->socket->sent = [];
    expect($server->connections()->findByTee($world, 0))->toBeNull();
    $server->sendToTee($world, 0, new SvChatChunk(0, -1, 'Not in game'));
    $server->kick($world, 0, 'Not in game');
    expect($server->socket->sent)->toBeEmpty();
    expect($other->state)->toBe(ConnectionSlot::STATE_INGAME);
})->with([
    ConnectionSlot::STATE_WAITING_INIT,
    ConnectionSlot::STATE_CONNECTING,
    ConnectionSlot::STATE_LOADING,
    ConnectionSlot::STATE_READY,
    ConnectionSlot::STATE_CLOSED,
]);

test('registered vote commands execute as the actual caller even without tee index zero', function (bool $removeFirst) {
    $server     = new WorldHelpersTestServer;
    $world      = $server->addWorld();
    $otherWorld = $server->addWorld();
    $server->addClient($otherWorld, 8400);
    $first        = $server->addClient($world, 8300);
    $caller       = $server->addClient($world, 8301);
    $command      = new WorldHelpersTestVoteCommand;
    $otherCommand = new WorldHelpersTestVoteCommand;
    $world->registerCommand($command);
    $otherWorld->registerCommand($otherCommand);

    if ($removeFirst) {
        $first->closeConnection('Leaving');
    }

    $server->socket->sent = [];
    $votes                = $world->getVoteController();
    $votes->callVote($world, $caller->playerTee(), 'OPTION', $command->getCommand(), '');
    if (! $removeFirst) {
        $votes->vote($world, $first->playerTee(), 1);
    }
    $votes->tick($world);

    expect($command->executions)->toBe([
        ['world' => $world, 'tee' => $caller->playerTee(), 'matches' => []],
    ]);
    expect($otherCommand->executions)->toBeEmpty();
    expect(array_column($server->socket->sent, 'port'))->not->toContain(8400);
    expect($votes->isVoteRunning())->toBeFalse();
})->with([false, true]);

test('option votes resolve menu labels command names and descriptions', function (string $value, string $description) {
    $server  = new WorldHelpersTestServer;
    $world   = $server->addWorld();
    $caller  = $server->addClient($world, 8300)->playerTee();
    $command = new WorldHelpersTestVoteCommand;
    $world->registerCommand($command);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Friendly option', $command->getCommand());

    $votes->callVote($world, $caller, 'OpTiOn', $value, '');
    $running = $votes->getRunningVote($caller);
    expect($running)->not->toBeNull();
    assert($running instanceof SvVoteSetChunk);
    expect($running->description)->toBe($description);
    $votes->tick($world);
    expect($command->executions)->toHaveCount(1);
})->with([
    'menu label'          => ['FRIENDLY OPTION', 'Friendly option'],
    'command name'        => ['record_vote', 'Recorded vote'],
    'command description' => ['Recorded vote', 'Recorded vote'],
]);

test('registered menu options take precedence over matching command descriptions', function () {
    $server   = new WorldHelpersTestServer;
    $world    = $server->addWorld();
    $caller   = $server->addClient($world, 8300)->playerTee();
    $first    = new WorldHelpersTestVoteCommand;
    $selected = new WorldHelpersTestVoteCommand('selected_vote', 'Selected vote');
    $world->registerCommand($first);
    $world->registerCommand($selected);
    $votes = $world->getVoteController();
    $votes->addVoteOption($first->getDescription(), $selected->getCommand());

    $votes->callVote($world, $caller, 'option', $first->getDescription(), '');
    $votes->tick($world);
    expect($first->executions)->toBeEmpty();
    expect($selected->executions)->toHaveCount(1);
});

test('chat-only commands are not resolved as vote commands', function (string $value) {
    $server  = new WorldHelpersTestServer;
    $world   = $server->addWorld();
    $caller  = $server->addClient($world, 8300)->playerTee();
    $command = new WorldHelpersTestVoteCommand(type: AbstractCommand::TYPE_CHAT);
    $world->registerCommand($command);
    $votes = $world->getVoteController();

    $votes->callVote($world, $caller, 'option', $value, '');
    $votes->tick($world);
    expect($votes->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBeEmpty();
    expect($server->socket->chatTexts())->toContain("'{$value}' isn't an option on this server");
})->with(['record_vote', 'Recorded vote']);

test('player votes reject self and missing local targets even when another world has that index', function (string $type, string $value, string $message) {
    $server     = new WorldHelpersTestServer;
    $world      = $server->addWorld();
    $otherWorld = $server->addWorld();
    $server->addClient($otherWorld, 8400);
    $server->addClient($otherWorld, 8401);
    $caller = $server->addClient($world, 8300)->playerTee();
    $votes  = $world->getVoteController();

    $votes->callVote($world, $caller, $type, $value, '');
    expect($votes->isVoteRunning())->toBeFalse();
    expect($server->socket->chatTexts())->toContain($message);
})->with([
    'self kick'               => ['KICK', '0', "You can't kick yourself"],
    'self spectate'           => ['SPECTATE', '0', "You can't move yourself"],
    'missing kick target'     => ['kick', '1', 'Invalid client id to kick'],
    'missing spectate target' => ['spectate', '1', 'Invalid client id to move'],
]);

test('successful player votes act only on the target world', function (string $type) {
    $server               = new WorldHelpersTestServer;
    $world                = $server->addWorld();
    $otherWorld           = $server->addWorld();
    $other                = $server->addClient($otherWorld, 8400);
    $target               = $server->addClient($world, 8300);
    $targetTee            = $target->playerTee();
    $caller               = $server->addClient($world, 8301)->playerTee();
    $votes                = $world->getVoteController();
    $server->socket->sent = [];

    $votes->callVote($world, $caller, $type, '0', 'Testing');
    $votes->vote($world, $targetTee, 1);
    $votes->tick($world);

    if ($type === 'kick') {
        expect($target->state)->toBe(ConnectionSlot::STATE_CLOSED);
        expect($world->findTee(0))->toBeNull();
    } else {
        expect($targetTee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    }

    expect($other->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($other->playerTee()->team)->toBe(GameConstants::TEAM_RED);
    expect(array_column($server->socket->sent, 'port'))->not->toContain(8400);
    expect($server->socket->chatTexts())->toContain('Vote passed');
})->with(['kick', 'spectate']);

test('target disconnects abort votes before an index can be reused', function (string $type) {
    $server = new WorldHelpersTestServer;
    $world  = $server->addWorld();
    $target = $server->addClient($world, 8300);
    $caller = $server->addClient($world, 8301)->playerTee();
    $votes  = $world->getVoteController();
    $votes->callVote($world, $caller, $type, '0', '');

    $target->closeConnection('Leaving');
    $replacement = $server->addClient($world, 8302);
    expect($replacement->playerTee()->teeIndex)->toBe(0);
    $votes->tick($world);

    expect($votes->isVoteRunning())->toBeFalse();
    expect($replacement->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($replacement->playerTee()->team)->toBe(GameConstants::TEAM_RED);
    expect($server->socket->chatTexts())->toContain('Vote aborted');
})->with(['kick', 'spectate']);

test('creator disconnects cancel execution and the next vote uses its own caller', function () {
    $server  = new WorldHelpersTestServer;
    $world   = $server->addWorld();
    $first   = $server->addClient($world, 8300)->playerTee();
    $caller  = $server->addClient($world, 8301);
    $command = new WorldHelpersTestVoteCommand;
    $world->registerCommand($command);
    $votes = $world->getVoteController();
    $votes->callVote($world, $caller->playerTee(), 'option', $command->getCommand(), '');

    $caller->closeConnection('Leaving');
    $replacement = $server->addClient($world, 8302)->playerTee();
    expect($replacement->teeIndex)->toBe(1);
    $votes->tick($world);
    expect($command->executions)->toBeEmpty();
    expect($server->socket->chatTexts())->toContain('Vote canceled');

    $votes->callVote($world, $first, 'option', $command->getCommand(), '');
    $votes->vote($world, $replacement, 1);
    $votes->tick($world);
    expect($command->executions)->toBe([
        ['world' => $world, 'tee' => $first, 'matches' => []],
    ]);
});
