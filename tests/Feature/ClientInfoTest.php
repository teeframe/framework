<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClChangeInfoChunk;
use TeeFrame\Network\Chunks\Game\ClStartInfoChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\System\EnterGameChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\RawPayload;
use TeeFrame\Network\SnapItems\ObjClientInfoItem;
use TeeFrame\Server\ConnectionHandler;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

function clientInfoTestSlot(AbstractWorld $world, TickHandler $clock, string $name = 'Original'): ConnectionSlot
{
    $socket = new class extends AbstractSocket
    {
        public function __construct() {}
    };
    $slot = new class($socket, $world, $clock) extends ConnectionSlot
    {
        protected function handlePacketSending(AbstractPacket $packet): bool
        {
            return true;
        }
    };
    $slot->init('127.0.0.1', 8300);
    $slot->playerTee()->setInfo($name, 'OldClan', 0, 'default', false, 0, 0);

    return $slot;
}

function clientInfoTestPacket(ConnectionSlot $slot, AbstractChunk $chunk): DefaultPacket
{
    $chunk->setSequence(($slot->ack + 1) % NetworkParams::MAXIMUM_ACK_NUMBER);
    $packet = PacketDecoder::decodeFromRaw((new DefaultPacket([$chunk], ack: $slot->sequence))->encodeToSend());
    if (! $packet instanceof DefaultPacket) {
        throw new RuntimeException('Could not decode client info test packet');
    }

    return $packet;
}

function clientInfoChange(string $name = 'Changed'): ClChangeInfoChunk
{
    return new ClChangeInfoChunk($name, 'NewClan', 76, 'bluekitty', true, 123456, 654321);
}

test('change info uses vanilla game message 21 and round-trips all profile fields', function (bool $customColor) {
    $chunk = (new ClChangeInfoChunk('Álice', 'Clã', 76, 'bluekitty', $customColor, 123456, 654321))->setSequence(5);
    $bytes = $chunk->encode();

    expect($bytes[3])->toBe(21 << 1);
    expect($chunk->isSystem())->toBeFalse();
    expect($chunk->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL);

    $decoded = PacketDecoder::decodeChunksFromPayload($bytes)[0];
    expect($decoded)->toBeInstanceOf(ClChangeInfoChunk::class);
    expect($decoded)->not->toBeInstanceOf(ClStartInfoChunk::class);
    assert($decoded instanceof ClChangeInfoChunk);
    expect($decoded->getSequence())->toBe(5);
    expect($decoded->getPayload()->encode())->toBe($chunk->getPayload()->encode());
})->with([false, true]);

test('change info rejects every truncated profile payload', function () {
    $bytes = clientInfoChange()->getPayload()->encode();
    for ($length = 0; $length < count($bytes); $length++) {
        expect(fn () => ClChangeInfoChunk::make(new RawPayload(array_slice($bytes, 0, $length))))
            ->toThrow(UnexpectedValueException::class);
    }
});

test('in-game profile changes update snapshots and announce the accepted name', function (bool $paused) {
    $clock       = new TickHandler(100);
    $world       = new TestWorld($clock, new Map);
    $slot        = clientInfoTestSlot($world, $clock);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $tee         = $slot->playerTee();
    $world->addTee($tee);
    $observer = clientInfoTestSlot($world, $clock, 'Observer')->playerTee();
    $world->addTee($observer);
    $world->setPaused($paused);
    $tee->score = 7;
    recordingServer($world)->clear();

    expect($slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange())))->toBeTrue();

    $info = $tee->doSnap($observer)[0];
    expect($info)->toBeInstanceOf(ObjClientInfoItem::class);
    assert($info instanceof ObjClientInfoItem);
    expect($info->name)->toBe('Changed');
    expect($info->clan)->toBe('NewClan');
    expect($info->country)->toBe(76);
    expect($info->skinName)->toBe('bluekitty');
    expect($info->useCustomColor)->toBeTrue();
    expect($info->colorBody)->toBe(123456);
    expect($info->colorFoot)->toBe(654321);
    expect($tee->lastChangeInfo)->toBe(100);
    expect($tee->score)->toBe(7);
    expect(recordingServer($world)->sentTeeIndexes)->toBe([$tee->teeIndex, $observer->teeIndex]);
    foreach (recordingServer($world)->sentChunks as $chat) {
        expect($chat)->toBeInstanceOf(SvChatChunk::class);
        assert($chat instanceof SvChatChunk);
        expect($chat->team)->toBe(0);
        expect($chat->clientId)->toBe(-1);
        expect($chat->text)->toBe("'Original' changed name to 'Changed'");
    }
})->with([false, true]);

test('appearance-only changes work for players and spectators without a rename announcement', function (int $team) {
    $clock       = new TickHandler(100);
    $world       = new TestWorld($clock, new Map);
    $slot        = clientInfoTestSlot($world, $clock);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $tee         = $slot->playerTee();
    $world->addTee($tee);
    $tee->team = $team;
    recordingServer($world)->clear();

    $slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange(' Original ')));

    expect($tee->name)->toBe('Original');
    expect($tee->skinName)->toBe('bluekitty');
    expect($tee->clan)->toBe('NewClan');
    expect($tee->team)->toBe($team);
    expect(recordingServer($world)->sentChunks)->toBeEmpty();
})->with([GameConstants::TEAM_RED, GameConstants::TEAM_SPECTATORS]);

test('profile changes enforce five seconds between accepted updates including at tick zero', function (int $startTick) {
    $clock       = new TickHandler($startTick);
    $world       = new TestWorld($clock, new Map);
    $slot        = clientInfoTestSlot($world, $clock);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $tee         = $slot->playerTee();
    $world->addTee($tee);
    $slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange('First')));
    recordingServer($world)->clear();

    for ($i = 0; $i < 5 * NetworkParams::TICKS_PER_SECOND - 1; $i++) {
        $clock->next();
    }
    $blocked = new ClChangeInfoChunk('Blocked', 'Blocked', 99, 'default', false, 0, 0);
    $slot->feedConnection(clientInfoTestPacket($slot, $blocked));

    expect($tee->name)->toBe('First');
    expect($tee->clan)->toBe('NewClan');
    expect($tee->country)->toBe(76);
    expect($tee->skinName)->toBe('bluekitty');
    expect($tee->useCustomColor)->toBeTrue();
    expect($tee->colorBody)->toBe(123456);
    expect($tee->colorFeet)->toBe(654321);
    expect($tee->lastChangeInfo)->toBe($startTick);
    expect(recordingServer($world)->sentChunks)->toBeEmpty();

    $clock->next();
    $slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange('Second')));
    expect($tee->name)->toBe('Second');
    expect($tee->lastChangeInfo)->toBe($startTick + 5 * NetworkParams::TICKS_PER_SECOND);
})->with([0, 100]);

test('profile name changes preserve world-local uniqueness and UTF-8 bounds', function () {
    $clock       = new TickHandler(100);
    $world       = new TestWorld($clock, new Map);
    $otherWorld  = new TestWorld($clock, new Map);
    $slot        = clientInfoTestSlot($world, $clock);
    $slot->state = ConnectionSlot::STATE_INGAME;
    $world->addTee($slot->playerTee());
    $existing = clientInfoTestSlot($world, $clock, 'Changed')->playerTee();
    $world->addTee($existing);
    $other = clientInfoTestSlot($otherWorld, $clock, '(1)Changed')->playerTee();
    $otherWorld->addTee($other);

    $slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange()));

    expect($slot->playerTee()->name)->toBe('(1)Changed');
    expect($existing->name)->toBe('Changed');
    expect($other->name)->toBe('(1)Changed');

    for ($i = 0; $i < 5 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->next();
    }
    $slot->feedConnection(clientInfoTestPacket($slot, clientInfoChange(str_repeat('é', 20))));
    expect($slot->playerTee()->name)->toBe(str_repeat('é', 7));
});

test('login names are checked once at world entry even when handshakes overlap', function (bool $reverseOrder) {
    $clock = new TickHandler(100);
    $world = new class($clock, new Map) extends TestWorld
    {
        public int $nameChecks = 0;

        public function setTeeName(AbstractTee $tee, string $name): void
        {
            $this->nameChecks++;
            parent::setTeeName($tee, $name);
        }
    };
    $handler = new ConnectionHandler($clock);
    $first   = clientInfoTestSlot($world, $clock);
    $second  = clientInfoTestSlot($world, $clock);
    foreach ([$first, $second] as $slot) {
        $slot->state = ConnectionSlot::STATE_READY;
        $startInfo   = new ClStartInfoChunk("Alice\x01", '', 0, 'default', false, 0, 0);
        expect($handler->handleConnectionHandshake($slot, clientInfoTestPacket($slot, $startInfo), ''))->toBeTrue();
    }
    expect($first->playerTee()->name)->toBe("Alice\x01");
    expect($second->playerTee()->name)->toBe("Alice\x01");
    expect($world->nameChecks)->toBe(0);
    expect($world->getTees())->toBeEmpty();

    [$earlier, $later] = $reverseOrder ? [$second, $first] : [$first, $second];
    foreach ([$earlier, $later] as $slot) {
        expect($handler->handleConnectionHandshake($slot, clientInfoTestPacket($slot, new EnterGameChunk), ''))->toBeTrue();
        expect($slot->state)->toBe(ConnectionSlot::STATE_INGAME);
    }

    expect($earlier->playerTee()->name)->toBe('Alice');
    expect($later->playerTee()->name)->toBe('(1)Alice');
    $names = array_map(fn ($tee) => $tee->name, $world->getTees());
    expect($names)->toBe(['Alice', '(1)Alice']);
    expect($world->nameChecks)->toBe(2);
})->with([false, true]);

test('change info cannot replace start info during the connection handshake', function () {
    $clock       = new TickHandler(100);
    $world       = new TestWorld($clock, new Map);
    $handler     = new ConnectionHandler($clock);
    $slot        = clientInfoTestSlot($world, $clock);
    $slot->state = ConnectionSlot::STATE_READY;

    $handler->handleConnectionHandshake($slot, clientInfoTestPacket($slot, clientInfoChange()), '');
    $handler->handleConnectionHandshake($slot, clientInfoTestPacket($slot, new EnterGameChunk), '');

    expect($slot->readyToEnter)->toBeFalse();
    expect($slot->state)->toBe(ConnectionSlot::STATE_READY);
    expect($slot->playerTee()->name)->toBe('Original');
    expect($world->getTees())->toBeEmpty();
});
