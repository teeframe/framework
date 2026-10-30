<?php

use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\System\ReadyChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Network\Packets\DefaultPacket;

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
