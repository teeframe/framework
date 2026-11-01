<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\Vote\VoteController;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionListAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteSetChunk;
use TeeFrame\Network\PacketDecoder;
use TeeFrame\Network\Packets\DefaultPacket;
use Tests\Support\RecordingConnection;

test('invalid vote descriptions are rejected at registration without modifying existing options', function (string $description) {
    $votes = new VoteController;
    $votes->addVoteOption('Existing', 'existing_command');

    expect(fn () => $votes->addVoteOption($description, 'invalid_command'))->toThrow(InvalidArgumentException::class);

    $chunks = $votes->getVoteChunks(new PlayerTee);
    expect($chunks)->toHaveCount(1);
    $chunk = $chunks[0];
    assert($chunk instanceof SvVoteOptionListAddChunk);
    expect($chunk->descriptions)->toBe(['Existing']);
})->with([
    'empty'                    => '',
    'spaces only'              => '   ',
    'blank controls'           => "\t\r\n",
    '64 ASCII bytes'           => str_repeat('a', 64),
    '128 ASCII bytes'          => str_repeat('a', 128),
    '64 UTF-8 bytes'           => str_repeat('é', 32),
    '64 four-byte UTF-8 bytes' => str_repeat('🎮', 16),
    'embedded terminator'      => "Option\0hidden",
]);

test('valid vote descriptions round-trip through the menu and remain callable without truncation', function (string $description) {
    $world = new TestWorld(new TickHandler(100), new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $votes = $world->getVoteController();
    $votes->addVoteOption($description, 'example_command');
    $chunk   = $votes->getVoteChunks($tee)[0];
    $decoded = PacketDecoder::decodeChunksFromPayload($chunk->setSequence(1)->encode())[0];
    assert($decoded instanceof SvVoteOptionListAddChunk);
    expect($decoded->descriptions)->toBe([$description]);

    $world->onMessage($tee, new ClCallVoteChunk('option', $decoded->descriptions[0], ''));

    expect($votes->isVoteRunning())->toBeTrue();
    $vote = $votes->getRunningVote($world);
    assert($vote instanceof SvVoteSetChunk);
    expect($vote->description)->toBe($description);
})->with([
    'one byte'             => 'a',
    '63 ASCII bytes'       => str_repeat('a', 63),
    '63 UTF-8 bytes'       => str_repeat('é', 31).'a',
    '63 mixed UTF-8 bytes' => str_repeat('🎮', 15).'abc',
    'spaces are preserved' => '  Reload  ',
]);

test('a full batch of maximum-length vote descriptions fits the initial menu packets', function () {
    $clock        = new TickHandler(100);
    $votes        = new VoteController;
    $descriptions = [];
    for ($i = 0; $i < SvVoteOptionListAddChunk::MAX_OPTIONS; $i++) {
        $description    = str_pad('Option '.$i, 63, 'x');
        $descriptions[] = $description;
        $votes->addVoteOption($description, 'command_'.$i);
    }
    $connection = new RecordingConnection($clock);
    foreach ($votes->getInitialVoteChunks(new PlayerTee) as $chunk) {
        $connection->chunks()->add($chunk);
    }

    expect($connection->chunks()->send())->toBeTrue();
    expect($connection->sentPackets)->toHaveCount(1);
    $packet = PacketDecoder::decodeFromRaw($connection->sentPackets[0]->encodeToSend());
    assert($packet instanceof DefaultPacket);
    expect($packet->getChunks())->toHaveCount(2);
    $options = $packet->getChunks()[1];
    assert($options instanceof SvVoteOptionListAddChunk);
    expect($options->descriptions)->toBe($descriptions);
});

test('oversized descriptions cannot be truncated into an existing option', function () {
    $votes       = new VoteController;
    $description = str_repeat('a', 63);
    $votes->addVoteOption($description, 'original_command');

    expect(fn () => $votes->addVoteOption($description.'b', 'replacement_command'))->toThrow(InvalidArgumentException::class);
    $votes->addVoteOption(strtoupper($description), 'duplicate_command');

    $options = $votes->getVoteChunks(new PlayerTee);
    expect($options)->toHaveCount(1);
    assert($options[0] instanceof SvVoteOptionListAddChunk);
    expect($options[0]->descriptions)->toBe([$description]);
});
