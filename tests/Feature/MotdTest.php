<?php

use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\PacketDecoder;

test('MOTD uses the Teeworlds 0.6 vital game message header', function () {
    $chunk = (new SvMotdChunk('Hi'))->setSequence(1);

    expect($chunk->isSystem())->toBeFalse();
    expect($chunk->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL);
    expect($chunk->encode())->toBe([
        0x40, 0x04, 0x01, // Vital chunk header: four payload bytes, sequence 1
        0x02, // SV_MOTD (1 << 1), not SV_BROADCAST (2 << 1)
        0x48, 0x69, 0x00, // Null-terminated "Hi"
    ]);
});

test('MOTD decodes as MOTD and preserves its text', function (string $text) {
    $chunk = (new SvMotdChunk($text))->setSequence(1);

    $decoded = PacketDecoder::decodeChunksFromPayload($chunk->encode());

    expect($decoded)->toHaveCount(1);
    expect($decoded[0])->toBeInstanceOf(SvMotdChunk::class);

    assert($decoded[0] instanceof SvMotdChunk);
    expect($decoded[0]->text)->toBe($text);
    expect($decoded[0]->getSequence())->toBe(1);
})->with([
    'welcome'   => 'Welcome to TeeFrame DM!',
    'multiline' => "Welcome to TeeFrame!\nEnjoy your stay.",
    'empty'     => '',
]);
