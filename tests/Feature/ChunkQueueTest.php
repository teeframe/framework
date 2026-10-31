<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvMotdChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Network\RawPayload;
use Tests\Support\RecordingConnection;

class QueueEncodingTestChunk extends AbstractChunk
{
    public int $encodes = 0;

    public function __construct(public int $payloadSize = 1, bool $vital = false)
    {
        parent::__construct($vital ? NetworkBase::CHUNK_FLAG_VITAL : 0, NetworkMessages::CL_VOTE);
    }

    public static function make(RawPayload $payload): static
    {
        return new static;
    }

    public function getPayload(): RawPayload
    {
        $this->encodes++;

        return new RawPayload(array_fill(0, $this->payloadSize, 0));
    }
}

test('only vital chunks are retained for retransmission', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()
        ->add(new QueueEncodingTestChunk)
        ->add($vital = new QueueEncodingTestChunk(vital: true))
        ->send();
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount(2);
    $resent = $connection->sentPackets[1];
    expect($resent->getNumChunks())->toBe(1);
    expect($resent->isResend())->toBeFalse();
    assert($resent instanceof DefaultPacket);
    $chunk = $resent->getChunks()[0];
    expect($chunk->getSequence())->toBe(1);
    expect($chunk->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL | NetworkBase::CHUNK_FLAG_RESEND);
    expect($vital->getFlags())->toBe(NetworkBase::CHUNK_FLAG_VITAL);
});

test('retransmissions respect the packet byte limit without allocating new sequences', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()
        ->add(new QueueEncodingTestChunk(900, vital: true))
        ->send();
    $connection->chunks()
        ->add(new QueueEncodingTestChunk(900, vital: true))
        ->send();
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount(4);
    expect($connection->sequence)->toBe(2);
    foreach (array_slice($connection->sentPackets, 2) as $index => $packet) {
        expect(strlen($packet->encodeToSend()))->toBeLessThanOrEqual(NetworkParams::MAXIMUM_PACKET_SIZE);
        expect($packet->getNumChunks())->toBe(1);
        expect($packet->isResend())->toBeFalse();
        assert($packet instanceof DefaultPacket);
        expect($packet->getChunks()[0]->getSequence())->toBe($index + 1);
    }
});

test('retransmissions respect the maximum number of chunks per packet', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 260; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(0, vital: true));
    }
    $connection->chunks()->send();
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount(4);
    expect($connection->sentPackets[2]->getNumChunks())->toBe(255);
    expect($connection->sentPackets[3]->getNumChunks())->toBe(5);
    expect($connection->sequence)->toBe(260);
});

test('repeated resends do not duplicate retained entries and acknowledged chunks stop resending', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()->add(new QueueEncodingTestChunk(vital: true))->send();
    $connection->chunks()->resend();
    $connection->chunks()->resend();
    $connection->chunks()->flushSentList(1);
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount(3);
    foreach ($connection->sentPackets as $packet) {
        expect($packet->getNumChunks())->toBe(1);
    }
    expect($connection->sequence)->toBe(1);
});

test('acknowledgment cleanup preserves pending sequences across wraparound', function () {
    $connection           = new RecordingConnection(new TickHandler);
    $connection->sequence = 1022;
    for ($i = 0; $i < 3; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(vital: true));
    }
    $connection->chunks()->send();
    $connection->chunks()->flushSentList(0);
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount(2);
    $packet = $connection->sentPackets[1];
    assert($packet instanceof DefaultPacket);
    expect($packet->getNumChunks())->toBe(1);
    expect($packet->getChunks()[0]->getSequence())->toBe(1);
    expect($connection->sequence)->toBe(1);
});

test('empty queues and cleared retransmission lists do not emit empty packets', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()->send();
    $connection->chunks()->resend();
    expect($connection->sentPackets)->toBeEmpty();

    $connection->chunks()->add(new QueueEncodingTestChunk(vital: true))->send();
    $connection->chunks()->reset();
    $connection->chunks()->resend();
    expect($connection->sentPackets)->toHaveCount(1);
});

test('queue size accounting does not repeatedly encode already queued chunks', function () {
    $connection = new RecordingConnection(new TickHandler);
    $chunks     = [];
    for ($i = 0; $i < 64; $i++) {
        $chunk    = new QueueEncodingTestChunk(vital: true);
        $chunks[] = $chunk;
        $connection->chunks()->add($chunk);
    }
    $connection->chunks()->send();

    expect($connection->sentPackets)->toHaveCount(1);
    foreach ($chunks as $index => $chunk) {
        expect($chunk->encodes)->toBe(2);
        expect($chunk->getSequence())->toBe($index + 1);
    }
});

test('packet batching permits the exact byte limit and flushes before overflow', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()
        ->add(new QueueEncodingTestChunk(696))
        ->add(new QueueEncodingTestChunk(695));
    expect($connection->sentPackets)->toBeEmpty();

    $connection->chunks()->add(new QueueEncodingTestChunk)->send();
    expect($connection->sentPackets)->toHaveCount(2);
    expect(strlen($connection->sentPackets[0]->encodeToSend()))->toBe(NetworkParams::MAXIMUM_PACKET_SIZE);
    expect($connection->sentPackets[0]->getNumChunks())->toBe(2);
    expect($connection->sentPackets[1]->getNumChunks())->toBe(1);
});

test('packet batching flushes before the chunk count exceeds the header capacity', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i <= NetworkParams::MAXIMUM_CHUNKS_PER_PACKET; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk);
    }
    $connection->chunks()->send();

    expect($connection->sentPackets)->toHaveCount(2);
    expect($connection->sentPackets[0]->getNumChunks())->toBe(255);
    expect($connection->sentPackets[1]->getNumChunks())->toBe(1);
});

test('cached queue sizes are reset by sending and clearing queued chunks', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()->add(new QueueEncodingTestChunk(696))->add(new QueueEncodingTestChunk(695))->send();
    $connection->chunks()->add(new QueueEncodingTestChunk(696))->add(new QueueEncodingTestChunk(695));
    $connection->chunks()->reset();
    $connection->chunks()->add(new QueueEncodingTestChunk)->send();

    expect($connection->sentPackets)->toHaveCount(2);
    expect($connection->sentPackets[0]->getNumChunks())->toBe(2);
    expect($connection->sentPackets[1]->getNumChunks())->toBe(1);
});

test('a chunk exceeding its length field is rejected without changing the existing queue', function (bool $vital) {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()->add(new QueueEncodingTestChunk(vital: true));

    expect(fn () => $connection->chunks()->add(new QueueEncodingTestChunk(1023, vital: $vital)))
        ->toThrow(RuntimeException::class, 'Chunk exceeds the 10-bit size limit');
    expect($connection->sentPackets)->toBeEmpty();
    expect($connection->sequence)->toBe(1);

    $connection->chunks()->send();
    expect($connection->sentPackets)->toHaveCount(1);
    expect($connection->sentPackets[0]->getNumChunks())->toBe(1);
})->with([false, true]);

test('the maximum chunk body fits its length field and survives sending and retransmission', function (bool $vital) {
    $connection = new RecordingConnection(new TickHandler);
    $text       = str_repeat('x', 1021);
    $chunk      = (new SvMotdChunk($text))->setFlags($vital ? NetworkBase::CHUNK_FLAG_VITAL : 0);
    $connection->chunks()->add($chunk)->send();
    $connection->chunks()->resend();

    expect($connection->sentPackets)->toHaveCount($vital ? 2 : 1);
    foreach ($connection->sentPackets as $packet) {
        $decoded = PacketDecoder::decodeFromRaw($packet->encodeToSend());
        expect($decoded)->toBeInstanceOf(DefaultPacket::class);
        assert($decoded instanceof DefaultPacket);
        $decodedChunk = $decoded->getChunks()[0];
        expect($decodedChunk)->toBeInstanceOf(SvMotdChunk::class);
        assert($decodedChunk instanceof SvMotdChunk);
        expect($decodedChunk->text)->toBe($text);
        $encoded = $decodedChunk->encode();
        expect((($encoded[0] & 0x3F) << 4) | ($encoded[1] & 0xF))->toBe(1023);
        expect(count($encoded))->toBe($vital ? 1026 : 1025);
    }
})->with([false, true]);

test('pending vital chunks cannot enter the ambiguous half of the sequence space', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 511; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(0, vital: true));
    }
    $connection->chunks()->send();
    $connection->chunks()->flushSentList(0);
    $connection->sentPackets = [];
    $connection->chunks()->resend();
    expect(array_sum(array_map(fn ($packet) => $packet->getNumChunks(), $connection->sentPackets)))->toBe(511);

    $connection->sentPackets = [];
    $connection->chunks()->add(new QueueEncodingTestChunk(0, vital: true));
    expect($connection->sequence)->toBe(511);
    expect($connection->chunks()->send())->toBeFalse();
    expect($connection->chunks()->update())->toBe('Too weak connection (out of buffer)');
    expect($connection->sentPackets)->toBeEmpty();
});

test('the resend byte budget includes queued vital chunks and accepts its exact limit', function () {
    $connection = new RecordingConnection(new TickHandler);
    // Each vital chunk occupies 1024 encoded bytes.
    for ($i = 0; $i < 32; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(1020, vital: true));
    }
    expect($connection->chunks()->send())->toBeTrue();
    expect($connection->chunks()->update())->toBeNull();
    $connection->sentPackets = [];
    expect($connection->chunks()->resend())->toBeTrue();
    expect($connection->sentPackets)->toHaveCount(32);

    $connection->sentPackets = [];
    $connection->chunks()->add(new QueueEncodingTestChunk(vital: true));
    expect($connection->sequence)->toBe(32);
    expect($connection->chunks()->send())->toBeFalse();
    expect($connection->chunks()->update())->toBe('Too weak connection (out of buffer)');
    expect($connection->sentPackets)->toBeEmpty();
});

test('resend exhaustion is detected before flushing the last reserved queued chunk', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 32; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(1020, vital: true));
    }
    expect($connection->sentPackets)->toHaveCount(31);
    $connection->chunks()->add(new QueueEncodingTestChunk(1020, vital: true));
    expect($connection->sentPackets)->toHaveCount(31);
    expect($connection->sequence)->toBe(32);
    expect($connection->chunks()->update())->toBe('Too weak connection (out of buffer)');
});

test('acknowledgments release resend capacity across sequence wraparound', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 3; $i++) {
        for ($j = 0; $j < 500; $j++) {
            $connection->chunks()->add(new QueueEncodingTestChunk(vital: true));
        }
        expect($connection->chunks()->send())->toBeTrue();
        $connection->chunks()->flushSentList($connection->sequence);
        $connection->peerAck = $connection->sequence;
        expect($connection->chunks()->update())->toBeNull();
    }
    expect($connection->sequence)->toBe(476);
    $connection->sentPackets = [];
    $connection->chunks()->resend();
    expect($connection->sentPackets)->toBeEmpty();
});

test('acknowledgments release the byte budget and non-vital data does not consume it', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 32; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(1020, vital: true));
    }
    $connection->chunks()->send();
    $connection->chunks()->add(new QueueEncodingTestChunk(1020))->send();
    expect($connection->chunks()->update())->toBeNull();
    $connection->chunks()->flushSentList(16);
    $connection->peerAck = 16;
    for ($i = 0; $i < 16; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(1020, vital: true));
    }
    expect($connection->chunks()->send())->toBeTrue();
    expect($connection->chunks()->update())->toBeNull();
    expect($connection->sequence)->toBe(48);
});

test('reset clears resend exhaustion and queued reservations', function () {
    $connection = new RecordingConnection(new TickHandler);
    for ($i = 0; $i < 600; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(0, vital: true));
    }
    expect($connection->chunks()->update())->toBe('Too weak connection (out of buffer)');
    $connection->reset();
    expect($connection->chunks()->update())->toBeNull();
    $connection->sentPackets = [];
    for ($i = 0; $i < 511; $i++) {
        $connection->chunks()->add(new QueueEncodingTestChunk(0, vital: true));
    }
    expect($connection->chunks()->send())->toBeTrue();
    expect($connection->sequence)->toBe(511);
});

test('direct chunk encoding rejects bodies larger than 1023 bytes', function (bool $vital, int $payloadSize) {
    $chunk = new QueueEncodingTestChunk($payloadSize, vital: $vital);

    expect(fn () => $chunk->encode())->toThrow(RuntimeException::class, 'Chunk exceeds the 10-bit size limit');
})->with([false, true])->with([1023, 1100, 1400]);
