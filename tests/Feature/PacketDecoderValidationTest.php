<?php

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\System\InfoChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\RawPayload;

/** @param int[] $payload */
function decoderValidationPacket(array $payload, int $numChunks = 1, bool $compressed = false): string
{
    return NetworkBase::packBuffer([
        $compressed ? NetworkBase::PACKET_FLAG_COMPRESSION << 4 : 0,
        0,
        $numChunks,
        ...($compressed ? NetworkBase::compressHuffman($payload) : $payload),
    ]);
}

test('chunk factories construct their defining class regardless of subclass constructors', function (AbstractChunk $chunk) {
    $decoded = $chunk::make($chunk->getPayload());

    expect($decoded::class)->toBe(get_parent_class($chunk));
    expect($decoded)->not->toBe($chunk);
    expect($decoded->encode())->toBe($chunk->encode());
})->with([
    'game message' => fn () => new class('Team chat') extends ClSayChunk
    {
        public function __construct(string $text)
        {
            parent::__construct(true, $text);
        }
    },
    'system message' => fn () => new class('test-password') extends InfoChunk
    {
        public function __construct(string $password)
        {
            parent::__construct('0.6', $password);
        }
    },
    'empty message' => fn () => new class(1) extends ReadyChunk
    {
        public function __construct(public int $marker)
        {
            parent::__construct();
        }
    },
]);

test('malformed chunk boundaries reject the whole packet', function (array $payload) {
    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket($payload)))->toBeFalse();
    expect(fn () => PacketDecoder::decodeChunksFromPayload($payload))->toThrow(UnexpectedValueException::class);
})->with([
    'partial non-vital header'  => [[0]],
    'partial vital header'      => [[0x40, 1]],
    'missing non-vital message' => [[0, 1]],
    'missing vital message'     => [[0x40, 1, 0]],
    'zero size non-vital chunk' => [[0, 0]],
    'zero size vital chunk'     => [[0x40, 0, 0]],
    'truncated non-vital body'  => [[0, 15, 0, 0]],
    'truncated vital body'      => [[0x40, 15, 1, 0, 0]],
]);

test('truncated integer fields reject packets instead of fabricating input', function (array $bytes, bool $compressed) {
    $payload = [0x40, count($bytes) + 1, 1, NetworkMessages::CL_VOTE << 1, ...$bytes];

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket($payload, compressed: $compressed)))->toBeFalse();
})->with([
    'missing integer'                => [[]],
    'missing continuation byte'      => [[0x80]],
    'missing last continuation byte' => [[0x80, 0x80, 0x80, 0x80]],
    'integer overflow'               => [[0x80, 0x80, 0x80, 0x80, 0x10]],
    'unterminated integer'           => [[0x80, 0x80, 0x80, 0x80, 0x80]],
])->with([false, true]);

test('unterminated string fields reject packets', function (array $bytes, bool $compressed) {
    $size    = count($bytes);
    $payload = [0x40 | ($size >> 4), $size & 0xF, 1, ...$bytes];

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket($payload, compressed: $compressed)))->toBeFalse();
    expect(fn () => PacketDecoder::decodeChunksFromPayload($payload))->toThrow(UnexpectedValueException::class, 'Unterminated string');
})->with([
    'missing chat text'               => [[NetworkMessages::CL_SAY << 1, 0]],
    'unterminated chat text'          => [[NetworkMessages::CL_SAY << 1, 0, 104, 105]],
    'missing vote reason'             => [[NetworkMessages::CL_CALLVOTE << 1, 111, 0, 118, 0]],
    'unterminated vote reason'        => [[NetworkMessages::CL_CALLVOTE << 1, 111, 0, 118, 0, 114]],
    'missing handshake password'      => [[(NetworkMessages::INFO << 1) | 1, 118, 0]],
    'unterminated handshake password' => [[(NetworkMessages::INFO << 1) | 1, 118, 0, 112]],
])->with([false, true]);

test('string terminators cannot be read from the next chunk', function (bool $compressed) {
    $malformed = [0x40, 4, 1, NetworkMessages::CL_SAY << 1, 0, 104, 105];
    $ready     = (new ReadyChunk)->setSequence(2)->encode();

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket([...$malformed, ...$ready], 2, $compressed)))->toBeFalse();
})->with([false, true]);

test('a malformed string in a later chunk rejects the whole packet', function (bool $compressed) {
    $ready     = (new ReadyChunk)->setSequence(1)->encode();
    $malformed = [0x40, 4, 2, NetworkMessages::CL_SAY << 1, 0, 104, 105];

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket([...$ready, ...$malformed], 2, $compressed)))->toBeFalse();
})->with([false, true]);

test('terminated empty strings remain valid in decoded messages', function (bool $compressed) {
    $chat   = (new ClSayChunk(false, ''))->setSequence(1)->encode();
    $vote   = (new ClCallVoteChunk('option', 'Reload', ''))->setSequence(2)->encode();
    $info   = (new InfoChunk('0.6 626fce9a778df4d4', ''))->setSequence(3)->encode();
    $packet = PacketDecoder::decodeFromRaw(decoderValidationPacket([...$chat, ...$vote, ...$info], 3, $compressed));

    expect($packet)->toBeInstanceOf(DefaultPacket::class);
    assert($packet instanceof DefaultPacket);
    [$decodedChat, $decodedVote, $decodedInfo] = $packet->getChunks();
    assert($decodedChat instanceof ClSayChunk);
    assert($decodedVote instanceof ClCallVoteChunk);
    assert($decodedInfo instanceof InfoChunk);
    expect($decodedChat->text)->toBe('');
    expect($decodedVote->reason)->toBe('');
    expect($decodedInfo->password)->toBe('');
})->with([false, true]);

test('raw string extraction requires a zero terminator', function (array $bytes) {
    expect(fn () => (new RawPayload($bytes))->extractString())->toThrow(UnexpectedValueException::class, 'Unterminated string');
})->with([
    'missing string'      => [[]],
    'unterminated string' => [[104, 105]],
]);

test('raw string extraction leaves subsequent fields intact', function () {
    $payload = new RawPayload([0, 104, 105, 0, 42]);

    expect($payload->extractString())->toBe('');
    expect($payload->extractString())->toBe('hi');
    expect($payload->extractInt())->toBe(42);
    expect($payload->encode())->toBeEmpty();
});

test('a malformed later chunk cannot produce a partially decoded packet', function () {
    $ready     = (new ReadyChunk)->setSequence(1)->encode();
    $malformed = [0x40, 2, 2, NetworkMessages::CL_VOTE << 1, 0x80];

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket([...$ready, ...$malformed], 2)))->toBeFalse();
});

test('integer continuation bytes cannot be read from the next chunk', function () {
    $malformed = [0x40, 2, 1, NetworkMessages::CL_VOTE << 1, 0x80];
    $ready     = (new ReadyChunk)->setSequence(2)->encode();

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket([...$malformed, ...$ready], 2)))->toBeFalse();
});

test('trailing partial chunk headers are rejected', function () {
    $ready = (new ReadyChunk)->setSequence(1)->encode();

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket([...$ready, 0])))->toBeFalse();
});

test('packet chunk counts must match the complete payload', function (int $declared, int $actual) {
    $payload = [];
    for ($i = 0; $i < $actual; $i++) {
        array_push($payload, ...(new ReadyChunk)->setSequence($i + 1)->encode());
    }

    expect(PacketDecoder::decodeFromRaw(decoderValidationPacket($payload, $declared)))->toBeFalse();
})->with([
    'missing chunk'    => [1, 0],
    'unreported chunk' => [0, 1],
    'too few chunks'   => [2, 1],
    'too many chunks'  => [1, 2],
]);

test('control packets must contain their control message byte', function () {
    $packet = NetworkBase::packBuffer([NetworkBase::PACKET_FLAG_TYPE_CONTROL << 4, 0, 0]);

    expect(PacketDecoder::decodeFromRaw($packet))->toBeFalse();
});

test('valid control packets still decode', function () {
    $packet = PacketDecoder::decodeFromRaw((new ControlMessage(NetworkMessages::CONTROL_KEEP_ALIVE))->encodeToSend());

    expect($packet)->toBeInstanceOf(ControlMessage::class);
    assert($packet instanceof ControlMessage);
    expect($packet->getControlMessage())->toBe(NetworkMessages::CONTROL_KEEP_ALIVE);
});

test('empty default packets remain valid', function () {
    $packet = PacketDecoder::decodeFromRaw(decoderValidationPacket([], 0));

    expect($packet)->toBeInstanceOf(DefaultPacket::class);
    assert($packet instanceof DefaultPacket);
    expect($packet->getChunks())->toBeEmpty();
});

test('minimum non-vital chunks are decoded even at the end of a packet', function () {
    $payload = [0, 1, 0xFE];
    $chunks  = PacketDecoder::decodeChunksFromPayload([...$payload, ...$payload]);

    expect($chunks)->toHaveCount(2);
    foreach ($chunks as $chunk) {
        expect($chunk)->toBeInstanceOf(UnsupportedChunk::class);
        assert($chunk instanceof UnsupportedChunk);
        expect($chunk->unsupportedMessage)->toBe(127);
        expect($chunk->getSequence())->toBe(-1);
    }

    $packet = PacketDecoder::decodeFromRaw(decoderValidationPacket($payload));
    expect($packet)->toBeInstanceOf(DefaultPacket::class);
    assert($packet instanceof DefaultPacket);
    expect($packet->getChunks())->toHaveCount(1);
});

test('decoded chunk flags preserve the wire header rather than constructor defaults', function (int $flags) {
    $chunk  = (new ClSayChunk(false, 'Transport flags'))->setFlags($flags)->setSequence(23);
    $packet = PacketDecoder::decodeFromRaw((new DefaultPacket([$chunk], ack: 0))->encodeToSend());

    expect($packet)->toBeInstanceOf(DefaultPacket::class);
    assert($packet instanceof DefaultPacket);
    $decoded = $packet->getChunks()[0];
    expect($decoded->getFlags())->toBe($flags);
    expect($decoded->getSequence())->toBe(($flags & NetworkBase::CHUNK_FLAG_VITAL) ? 23 : -1);
})->with([
    'non-vital'            => 0,
    'vital'                => NetworkBase::CHUNK_FLAG_VITAL,
    'vital retransmission' => NetworkBase::CHUNK_FLAG_VITAL | NetworkBase::CHUNK_FLAG_RESEND,
]);

test('valid mixed chunks preserve messages and vital sequences', function (bool $compressed) {
    $chat    = (new ClSayChunk(false, 'Hello'))->setSequence(1023);
    $ready   = (new ReadyChunk)->setSequence(0);
    $payload = [...$chat->encode(), 0, 1, 0xFE, ...$ready->encode()];
    $packet  = PacketDecoder::decodeFromRaw(decoderValidationPacket($payload, 3, $compressed));

    expect($packet)->toBeInstanceOf(DefaultPacket::class);
    assert($packet instanceof DefaultPacket);
    expect($packet->getNumChunks())->toBe(3);

    [$decodedChat, $unknown, $decodedReady] = $packet->getChunks();
    expect($decodedChat)->toBeInstanceOf(ClSayChunk::class);
    assert($decodedChat instanceof ClSayChunk);
    expect($decodedChat->text)->toBe('Hello');
    expect($decodedChat->team)->toBeFalse();
    expect($decodedChat->getSequence())->toBe(1023);
    expect($unknown)->toBeInstanceOf(UnsupportedChunk::class);
    expect($decodedReady)->toBeInstanceOf(ReadyChunk::class);
    expect($decodedReady->getSequence())->toBe(0);
})->with([false, true]);
