<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
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

test('a single oversized chunk is rejected without sending or discarding the existing queue', function () {
    $connection = new RecordingConnection(new TickHandler);
    $connection->chunks()->add(new QueueEncodingTestChunk);

    expect(fn () => $connection->chunks()->add(new QueueEncodingTestChunk(NetworkParams::MAXIMUM_PACKET_SIZE)))
        ->toThrow(RuntimeException::class, 'Chunk exceeds the packet size limit');
    expect($connection->sentPackets)->toBeEmpty();

    $connection->chunks()->send();
    expect($connection->sentPackets)->toHaveCount(1);
    expect($connection->sentPackets[0]->getNumChunks())->toBe(1);
});
