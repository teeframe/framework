<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvReadyToEnterChunk;
use TeeFrame\Network\Chunks\System\ConReadyChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\MapDataChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\System\RequestMapDataChunk;
use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

// The server decoder does not normally consume its own MAP_DATA replies.
class WorldTransferTestPacketDecoder extends PacketDecoder
{
    protected static function matchDecodedChunk(int $flags, int $message, bool $isSystem): UnsupportedChunk|string
    {
        return $isSystem && $message === NetworkMessages::MAP_DATA
            ? MapDataChunk::class
            : parent::matchDecodedChunk($flags, $message, $isSystem);
    }
}

class WorldTransferTestSocket extends AbstractSocket
{
    /** @var AbstractPacket[] */
    public array $packets = [];

    /** @var array<array{address: string, port: int, packet: AbstractPacket}> */
    public array $sent = [];

    public function __construct() {}

    public function sendto(mixed $ip, mixed $port, mixed $send_data, mixed $server_socket = -1): bool
    {
        assert(is_string($ip) && is_int($port) && is_string($send_data));
        $packet = WorldTransferTestPacketDecoder::decodeFromRaw($send_data);
        assert($packet instanceof AbstractPacket);
        $this->packets[] = $packet;
        $this->sent[] = ['address' => $ip, 'port' => $port, 'packet' => $packet];

        return true;
    }

    /** @return AbstractChunk[] */
    public function chunks(): array
    {
        $chunks = [];
        foreach ($this->packets as $packet) {
            if ($packet instanceof DefaultPacket) {
                array_push($chunks, ...$packet->getChunks());
            }
        }

        return $chunks;
    }
}

class WorldTransferTestCommand extends AbstractCommand
{
    public function __construct(private AbstractWorld $destination) {}

    public function getName(): string
    {
        return strtolower($this->destination->getGameController()->getGameType());
    }

    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        $world->getServer()->changeWorld($tee, $this->destination);
    }
}

class WorldTransferTestServer extends AbstractServerInstance
{
    public TestWorldWithController $dm;
    public TestWorldWithController $ctf;
    public WorldTransferTestSocket $socket;

    public function __construct(int $dmSlots = 64, int $ctfSlots = 64)
    {
        parent::__construct(new TickHandler(1000), name: 'Multiworld test');
        $this->socket = new WorldTransferTestSocket;
        $dmController = new class($this->tickHandler) extends AbstractGameController {};
        $ctfController = new class($this->tickHandler, isTeamMode: true, isCaptureTheFlag: true) extends AbstractGameController {};
        $this->dm = new TestWorldWithController($this->tickHandler, new Map(__DIR__.'/../dm1.map'), $dmController, $this, $dmSlots);
        $this->ctf = new TestWorldWithController($this->tickHandler, new Map(__DIR__.'/../ctf1.map'), $ctfController, $this, $ctfSlots);
        $this->worlds = [$this->dm, $this->ctf];
        foreach ($this->worlds as $world) {
            $world->registerCommand(new WorldTransferTestCommand($this->dm));
            $world->registerCommand(new WorldTransferTestCommand($this->ctf));
        }
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        return $this->dm;
    }

    public function receive(AbstractPacket $packet, int $port = 8300): void
    {
        $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
        assert($decoded instanceof AbstractPacket);
        $this->onPacket($decoded, ['address' => '127.0.0.1', 'port' => $port], $this->socket);
    }

    public function connect(int $port = 8300): ConnectionSlot
    {
        $this->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), $port);
        $slot = $this->connectionHandler->tryToMatch(['address' => '127.0.0.1', 'port' => $port]);
        assert($slot instanceof ConnectionSlot);

        return $slot;
    }

    public function send(ConnectionSlot $slot, AbstractChunk ...$chunks): void
    {
        $sequence = $slot->ack;
        foreach ($chunks as $chunk) {
            if ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL) {
                $sequence = ($sequence + 1) % 1024;
                $chunk->setSequence($sequence);
            }
        }
        $this->receive(new DefaultPacket($chunks, ack: $slot->sequence), $slot->destinationPort);
    }

    public function enter(ConnectionSlot $slot): void
    {
        if ($slot->state === ConnectionSlot::STATE_CONNECTING) {
            $this->send($slot, new InfoChunk('0.6 626fce9a778df4d4'));
        }
        $this->send($slot, new ReadyChunk);
        $this->send($slot, new ClStartInfoChunk('Traveler', 'Clan', 76, 'bluekitty', true, 123, 456));
        $this->send($slot, new EnterGameChunk);
    }

    public function advance(int $ticks = 1): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $this->doTick();
        }
    }

    public function connectionCount(): int
    {
        return count($this->connectionHandler->getConnections());
    }
}

function worldTransferInput(int $tick = 1001, int $ackTick = -1): InputChunk
{
    return new InputChunk($ackTick, $tick, 40, 1, 0, -1, false, 1, false, GameConstants::PLAYERFLAG_PLAYING, 0, 0, 0);
}

/** @return string[] */
function worldTransferInfoFields(WorldTransferTestSocket $socket, int $port): array
{
    foreach (array_reverse($socket->sent) as $sent) {
        $packet = $sent['packet'];
        if ($sent['port'] === $port && $packet instanceof ConnectionlessPacket
            && str_starts_with($packet->getData(), NetworkMessages::SERVERBROWSE_INFO_EXTENDED)) {
            return explode("\0", substr($packet->getData(), strlen(NetworkMessages::SERVERBROWSE_INFO_EXTENDED), -1));
        }
    }

    throw new RuntimeException('Missing server info for port '.$port);
}

function worldTransferInfoCount(WorldTransferTestSocket $socket): int
{
    return count(array_filter($socket->sent, fn ($sent) => $sent['packet'] instanceof ConnectionlessPacket));
}

test('new clients enter DM through the normal map handshake and transport ACKs advance', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    expect($slot->world())->toBe($server->dm);
    expect($slot->state)->toBe(ConnectionSlot::STATE_CONNECTING);

    $server->enter($slot);
    $server->advance();

    expect($slot->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($slot->ack)->toBe(4);
    expect($slot->playerTee()->name)->toBe('Traveler');
    expect($slot->playerTee()->character?->alive)->toBeTrue();
    expect($server->dm->getTees())->toHaveCount(1);
    expect($server->ctf->getTees())->toBeEmpty();
    expect($server->ctf->getGameController()->getFlag(GameConstants::TEAM_RED))->not()->toBeNull();
    expect($server->ctf->getGameController()->getFlag(GameConstants::TEAM_BLUE))->not()->toBeNull();
});

test('a chat command transfers a player without resetting the transport or leaking world state', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    $server->enter($slot);
    $server->advance();
    $oldTee = $slot->playerTee();
    $character = $oldTee->character;
    $oldTee->score = 15;
    $oldTee->inputs[1002] = input(['direction' => 1]);
    $oldTee->vote = 1;
    $oldTee->spectatorId = 10;
    $oldSequence = $slot->sequence;
    $oldAck = $slot->ack;
    $server->socket->packets = [];

    $server->send($slot, new ClSayChunk(false, '/ctf'), worldTransferInput(), new ClSayChunk(false, '/dm'));

    expect($slot->world())->toBe($server->ctf);
    expect($slot->state)->toBe(ConnectionSlot::STATE_LOADING);
    expect($slot->ack)->toBe($oldAck + 2);
    expect($slot->sequence)->toBeGreaterThan($oldSequence);
    expect($slot->destinationAddress)->toBe('127.0.0.1');
    expect($slot->destinationPort)->toBe(8300);
    expect($server->connectionCount())->toBe(1);
    expect($character?->alive)->toBeFalse();
    expect($server->dm->getTees())->toBeEmpty();
    expect($server->ctf->getTees())->toBeEmpty();

    $tee = $slot->playerTee();
    expect($tee)->not()->toBe($oldTee);
    expect([$tee->name, $tee->clan, $tee->country, $tee->skinName, $tee->useCustomColor, $tee->colorBody, $tee->colorFeet])
        ->toBe(['Traveler', 'Clan', 76, 'bluekitty', true, 123, 456]);
    expect($tee->character)->toBeNull();
    expect($tee->inputs)->toBeEmpty();
    expect($tee->score)->toBe(0);
    expect($tee->vote)->toBe(0);
    expect($tee->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($tee->lastChatTick)->toBe($oldTee->lastChatTick);
    $snapReflection = new ReflectionClass($slot->snaps());
    expect($snapReflection->getProperty('sentList')->getValue($slot->snaps()))->toBeEmpty();
    expect($snapReflection->getProperty('deltaSnap')->getValue($slot->snaps()))->toBeNull();
    expect($snapReflection->getProperty('lastAckedTick')->getValue($slot->snaps()))->toBe(-1);

    $changes = array_values(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof MapChangeChunk));
    expect($changes)->toHaveCount(1);

    expect([$changes[0]->mapName, $changes[0]->crc, $changes[0]->size])->toBe($server->ctf->getMapInfo());
});

test('map transfers repeat ready start-info and enter-game then start with fresh snapshots', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    $server->enter($slot);
    $server->advance();
    $server->send($slot, new ClSayChunk(false, '/ctf'));
    $server->socket->packets = [];

    $server->send($slot, worldTransferInput(), new EnterGameChunk);
    expect($slot->state)->toBe(ConnectionSlot::STATE_LOADING);
    expect($slot->playerTee()->inputs)->toBeEmpty();
    expect($server->ctf->getTees())->toBeEmpty();
    $server->send($slot, new ReadyChunk);
    expect($slot->state)->toBe(ConnectionSlot::STATE_READY);
    expect(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof ConReadyChunk))->toHaveCount(1);
    $server->enter($slot);
    expect($slot->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof ConReadyChunk))->toHaveCount(1);
    expect(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof SvReadyToEnterChunk))->toHaveCount(1);
    expect($server->dm->getTees())->toBeEmpty();
    expect($server->ctf->getTees())->toHaveCount(1);

    $server->socket->packets = [];
    $server->advance();
    expect($slot->playerTee()->character?->alive)->toBeTrue();
    $snapshots = array_values(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof SnapSingleChunk));
    expect($snapshots)->toHaveCount(1);
    expect($snapshots[0]->deltaTick)->toBe($snapshots[0]->currentTick + 1);

    $server->advance(60);
    $server->send($slot, new ClSayChunk(false, '/dm'));
    $server->enter($slot);
    $server->advance();
    expect($slot->world())->toBe($server->dm);
    expect($server->dm->getTees())->toHaveCount(1);
    expect($server->ctf->getTees())->toBeEmpty();
    expect($slot->playerTee()->character?->alive)->toBeTrue();
    expect($server->connectionCount())->toBe(1);
});

test('entering another world resolves name collisions and joins the smaller CTF team', function () {
    $server = new WorldTransferTestServer;
    $first = $server->connect();
    $server->enter($first);
    $server->changeWorld($first->playerTee(), $server->ctf);
    $server->enter($first);
    $second = $server->connect(8301);
    $server->enter($second);
    expect($second->playerTee()->name)->toBe('Traveler');

    $server->changeWorld($second->playerTee(), $server->ctf);
    $server->enter($second);

    expect($first->playerTee()->name)->toBe('Traveler');
    expect($second->playerTee()->name)->toBe('(1)Traveler');
    expect($first->playerTee()->team)->toBe(GameConstants::TEAM_RED);
    expect($second->playerTee()->team)->toBe(GameConstants::TEAM_BLUE);
    expect($server->ctf->getTees())->toHaveCount(2);
    expect($server->dm->getTees())->toBeEmpty();
});

test('map download requests after a transfer serve the destination map', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    $server->enter($slot);
    $server->changeWorld($slot->playerTee(), $server->ctf);
    $server->socket->packets = [];
    $map = $server->ctf->getMap();
    $lastChunk = intdiv($map->getSize() - 1, 896);

    $server->send($slot, new RequestMapDataChunk(0));
    $server->send($slot, new RequestMapDataChunk($lastChunk));

    $chunks = array_values(array_filter($server->socket->chunks(), fn ($chunk) => $chunk instanceof MapDataChunk));
    expect($chunks)->toHaveCount(2);

    expect($chunks[0]->crc)->toBe($map->getCrc());
    expect($chunks[0]->data)->toBe(array_slice($map->getRawData(), 0, 896));
    expect($chunks[1]->last)->toBe(1);
    expect($chunks[1]->data)->toBe(array_slice($map->getRawData(), $lastChunk * 896));
    expect($chunks[1]->chunkSize)->toBe($map->getSize() - $lastChunk * 896);
});

test('a loading client reserves its destination slot and a rejected switch leaves the source intact', function () {
    $server = new WorldTransferTestServer(ctfSlots: 1);
    $first = $server->connect();
    $server->enter($first);
    expect($server->changeWorld($first->playerTee(), $server->ctf))->toBeTrue();
    $second = $server->connect(8301);
    $server->enter($second);
    $server->advance();
    $tee = $second->playerTee();
    $character = $tee->character;
    $server->socket->packets = [];

    expect($server->changeWorld($tee, $server->ctf))->toBeFalse();
    expect($second->world())->toBe($server->dm);
    expect($second->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($second->playerTee())->toBe($tee);
    expect($character?->alive)->toBeTrue();
    expect($server->socket->packets)->toBeEmpty();

    $server->enter($first);
    expect($server->changeWorld($first->playerTee(), $server->ctf))->toBeTrue();
    expect($server->ctf->getTees())->toHaveCount(1);
});

test('control close writes its message id once before the null-terminated rejection reason', function () {
    $reason = 'The entry-level world is full';

    expect((new ControlMessage(NetworkMessages::CONTROL_CLOSE, $reason))->encodeToSend())
        ->toBe("\x10\0\0\x04".$reason."\0");
});

test('a single-world server keeps the default server full rejection message', function () {
    $server = new WorldTransferTestServer(dmSlots: 1);
    (new ReflectionProperty($server, 'worlds'))->setValue($server, [$server->dm]);
    $server->connect();

    $server->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), 8301);

    expect($server->connectionCount())->toBe(1);
    $close = $server->socket->packets[count($server->socket->packets) - 1];
    assert($close instanceof ControlMessage);
    expect($close->getControlMessage())->toBe(NetworkMessages::CONTROL_CLOSE);
    expect(explode("\0", $close->getControlMessageExtra(), 2)[0])->toBe('The server is full');
});

test('a full entry world rejects connections even when other worlds have free slots', function () {
    $server = new WorldTransferTestServer(dmSlots: 1, ctfSlots: 1);
    $first = $server->connect();
    $server->enter($first);
    $server->receive(new ConnectionlessPacket(NetworkMessages::SERVERBROWSE_GETINFO.chr(42), extraToken: 0), 9000);
    $fields = worldTransferInfoFields($server->socket, 9000);
    expect(array_slice($fields, 8, 4))->toBe(['1', '2', '1', '2']);

    $server->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), 8301);
    expect($server->connectionCount())->toBe(1);
    $close = $server->socket->packets[count($server->socket->packets) - 1];
    assert($close instanceof ControlMessage);
    expect($close->getControlMessage())->toBe(NetworkMessages::CONTROL_CLOSE);
    expect(explode("\0", $close->getControlMessageExtra(), 2)[0])->toBe('The entry-level world is full');
    expect($first->world())->toBe($server->dm);
    expect($server->ctf->getTees())->toBeEmpty();

    expect($server->changeWorld($first->playerTee(), $server->ctf))->toBeTrue();
    $second = $server->connect(8301);
    $server->enter($second);
    expect($server->connectionCount())->toBe(2);
    $server->enter($first);
    expect($server->changeWorld($second->playerTee(), $server->ctf))->toBeFalse();
    $first->closeConnection('Free slot');
    expect($server->changeWorld($second->playerTee(), $server->ctf))->toBeTrue();
});

test('entry world slots are reserved throughout the handshake and released on disconnect', function (int $state) {
    $server = new WorldTransferTestServer(dmSlots: 1, ctfSlots: 4);
    $first = $server->connect();
    if ($state === ConnectionSlot::STATE_INGAME) {
        $server->enter($first);
    } else {
        if ($state >= ConnectionSlot::STATE_LOADING) {
            $server->send($first, new InfoChunk('0.6 626fce9a778df4d4'));
        }
        if ($state >= ConnectionSlot::STATE_READY) {
            $server->send($first, new ReadyChunk);
        }
    }
    expect($first->state)->toBe($state);

    $server->receive(new ControlMessage(NetworkMessages::CONTROL_CONNECT), 8301);

    expect($server->connectionCount())->toBe(1);
    $close = $server->socket->packets[count($server->socket->packets) - 1];
    assert($close instanceof ControlMessage);
    expect($close->getControlMessage())->toBe(NetworkMessages::CONTROL_CLOSE);
    expect(explode("\0", $close->getControlMessageExtra(), 2)[0])->toBe('The entry-level world is full');

    $first->closeConnection('Free entry slot');
    $next = $server->connect(8301);
    expect($next->state)->toBe(ConnectionSlot::STATE_CONNECTING);
    expect($next->world())->toBe($server->dm);
    expect($server->connectionCount())->toBe(1);
})->with([
    ConnectionSlot::STATE_CONNECTING,
    ConnectionSlot::STATE_LOADING,
    ConnectionSlot::STATE_READY,
    ConnectionSlot::STATE_INGAME,
]);

test('world changes reject unregistered destinations unknown tees and clients already loading', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    expect($server->changeWorld($slot->playerTee(), $server->ctf))->toBeFalse();
    $server->enter($slot);
    $foreignServer = new WorldTransferTestServer;

    expect($server->changeWorld($slot->playerTee(), $foreignServer->ctf))->toBeFalse();
    expect($server->changeWorld(new PlayerTee, $server->ctf))->toBeFalse();
    expect($server->changeWorld($slot->playerTee(), $server->ctf))->toBeTrue();
    expect($server->changeWorld($slot->playerTee(), $server->dm))->toBeFalse();
    expect($slot->world())->toBe($server->ctf);
});

test('Client receives world info on its gameplay socket after acknowledging a newer snapshot', function () {
    $server = new WorldTransferTestServer(dmSlots: 12, ctfSlots: 36);
    $slot = $server->connect();
    $server->enter($slot);
    $server->socket->sent = [];
    $server->advance(2);
    $sequence = $slot->sequence;
    $ack = $slot->ack;

    $server->send($slot, worldTransferInput(1003, ackTick: 1000));
    expect(worldTransferInfoCount($server->socket))->toBe(0);
    $server->send($slot, worldTransferInput(1003, ackTick: 1001));

    expect(worldTransferInfoCount($server->socket))->toBe(1);
    $fields = worldTransferInfoFields($server->socket, 8300);
    expect($fields[0])->toBe('-1');
    expect($fields[3])->toBe('dm1');
    expect($fields[6])->toBe('DM');
    expect(array_slice($fields, 8, 4))->toBe(['1', '12', '1', '12']);
    expect($fields)->not()->toContain('MW', 'MultiWorld');
    expect($slot->sequence)->toBe($sequence);
    expect($slot->ack)->toBe($ack);

    $server->send($slot, worldTransferInput(1004, ackTick: 1001));
    expect(worldTransferInfoCount($server->socket))->toBe(1);
});

test('Client receives the destination gamemode and capacity again after a world switch', function () {
    $server = new WorldTransferTestServer(dmSlots: 12, ctfSlots: 36);
    $slot = $server->connect();
    $server->enter($slot);
    $server->advance(2);
    $server->send($slot, worldTransferInput(1003, ackTick: 1001));
    expect(worldTransferInfoFields($server->socket, 8300)[6])->toBe('DM');
    $server->send($slot, new ClSayChunk(false, '/ctf'));
    $server->enter($slot);
    $server->socket->sent = [];

    $server->send($slot, worldTransferInput(1003, ackTick: 1001));
    expect(worldTransferInfoCount($server->socket))->toBe(0);
    $server->advance(2);
    $server->send($slot, worldTransferInput(1005, ackTick: 1003));

    $fields = worldTransferInfoFields($server->socket, 8300);
    expect($fields[0])->toBe('-1');
    expect($fields[3])->toBe('ctf1');
    expect($fields[6])->toBe('CTF');
    expect(array_slice($fields, 8, 4))->toBe(['1', '36', '1', '36']);
    expect($fields)->not()->toContain('MW', 'MultiWorld');
});

test('Client contact queries get aggregate info then each gameplay endpoint gets its own world', function () {
    $server = new WorldTransferTestServer(dmSlots: 32, ctfSlots: 64);
    $dm = $server->connect(8300);
    $server->enter($dm);
    $ctf = $server->connect(8301);
    $server->enter($ctf);
    $server->changeWorld($ctf->playerTee(), $server->ctf);
    $server->enter($ctf);
    $server->advance(2);
    $server->send($dm, worldTransferInput(1003, ackTick: 1001));
    $server->send($ctf, worldTransferInput(1003, ackTick: 1001));
    $server->socket->sent = [];

    $server->receive(new ConnectionlessPacket(NetworkMessages::SERVERBROWSE_GETINFO.chr(42), extraToken: 0x1234), 9000);

    expect(worldTransferInfoCount($server->socket))->toBe(1);
    $browserFields = worldTransferInfoFields($server->socket, 9000);
    expect($browserFields[0])->toBe('1193002');
    expect($browserFields[3])->toBe('MultiWorld');
    expect($browserFields[6])->toBe('MW');
    expect(array_slice($browserFields, 8, 4))->toBe(['2', '96', '2', '96']);
    $server->advance(2);
    $server->send($dm, worldTransferInput(1005, ackTick: 1002));
    $server->send($ctf, worldTransferInput(1005, ackTick: 1002));
    expect(worldTransferInfoCount($server->socket))->toBe(1);

    $server->send($dm, worldTransferInput(1005, ackTick: 1003));
    $server->send($ctf, worldTransferInput(1005, ackTick: 1003));

    expect(worldTransferInfoCount($server->socket))->toBe(3);
    $dmFields = worldTransferInfoFields($server->socket, 8300);
    $ctfFields = worldTransferInfoFields($server->socket, 8301);
    expect([$dmFields[0], $dmFields[3], $dmFields[6], $dmFields[11]])->toBe(['-1', 'dm1', 'DM', '32']);
    expect([$ctfFields[0], $ctfFields[3], $ctfFields[6], $ctfFields[11]])->toBe(['-1', 'ctf1', 'CTF', '64']);
    expect($dmFields[10])->toBe('1');
    expect($ctfFields[10])->toBe('1');
    expect(worldTransferInfoFields($server->socket, 9000))->toBe($browserFields);
});

test('reset and world loading do not send a stale queued world info update', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    $server->enter($slot);
    $server->changeWorld($slot->playerTee(), $server->ctf);
    $server->socket->sent = [];
    $server->send($slot, worldTransferInput(1003, ackTick: 1001));
    $server->sendWorldInfo($slot);
    expect(worldTransferInfoCount($server->socket))->toBe(0);

    $slot->queueWorldInfoUpdate();
    $slot->reset();
    $slot->state = ConnectionSlot::STATE_INGAME;
    $slot->feedConnection(new DefaultPacket([worldTransferInput(1003, ackTick: 1001)], ack: 0));
    expect(worldTransferInfoCount($server->socket))->toBe(0);
});

test('leaving CTF drops carried flags and resets spectators following the departing tee', function () {
    $server = new WorldTransferTestServer;
    $slot = $server->connect();
    $server->enter($slot);
    $server->changeWorld($slot->playerTee(), $server->ctf);
    $server->enter($slot);
    $server->advance();
    $tee = $slot->playerTee();
    $flag = $server->ctf->getGameController()->getFlag(GameConstants::TEAM_BLUE);
    assert($flag !== null);
    $flag->carryingCharacter = $tee->character;
    $flag->atStand = false;
    $spectator = new PlayerTee;
    $server->ctf->addTee($spectator);
    $spectator->team = GameConstants::TEAM_SPECTATORS;
    $spectator->spectatorId = $tee->teeIndex;

    expect($server->changeWorld($tee, $server->dm))->toBeTrue();

    expect($flag->carryingCharacter)->toBeNull();
    expect($flag->atStand)->toBeFalse();
    expect($spectator->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
});
