<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
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
