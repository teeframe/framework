<?php

use TeeFrame\Network\Chunks\System\SnapSingleChunk;
use TeeFrame\Network\Chunks\UnsupportedChunk;
use TeeFrame\Network\Connection\AbstractConnection;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\SnapItems\AbstractSnapItem;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjClientInfoItem;
use TeeFrame\Network\SnapItems\ObjGameInfoItem;
use TeeFrame\Network\SnapItems\ObjPickupItem;
use TeeFrame\Network\SnapItems\ObjPlayerInfoItem;

/**
 * @return int[]
 */
function getExpectedPacket(): array
{
    return NetworkBase::unpackBuffer(
        "\x00\x06\x01". // Header
        "\x09\x01\x0f\xaa\x4d\xab\x4d\x90\xde\xaf\xf2\x06\x85\x02". // Snap Header
        "\x00\x05\x00". // Removed, Updated Items & Unused
        "\x04\x00\x90\x1b\xb0\x10\x02\x03". // PickUp
        "\x09\x00\x9f\x4d\xb0\x18\xb1\x04\x00\x80\x02\x00\x00\x00\x40\x00\x00\xb0\x18\xb0\x04\x00\x00\x00\x0a\x00\x0a\x01\x00\x00". // Character
        "\x06\x00\x00\x00\x00\x00\x14\x00\x00\x01". // Game Info
        "\x0b\x00\xde\xd0\xf0\xc1\x02\xff\xfd\xfb\xf7\x0f\xff\xfd\xfb\xf7\x0f\xff\xff\xfb\xf7\x0f\xff\xfd\xab\xc1\x02\xff\xfd\xfb\xf7\x0f\xff\xff\xfb\xf7\x0f\x40\xde\xe4\xd0\xb1\x03\xff\xad\x98\xa1\x01\xff\xfd\xfb\xf7\x0f\xff\xfd\xfb\xf7\x0f\xff\xfd\xfb\xf7\x0f\xff\xff\xfb\xf7\x0f\x00\x80\xfe\x07\x80\xfe\x07". // Client Info
        "\x0a\x00\x01\x00\x00\x00\x00" // Player Info
    );
}

/**
 * @return AbstractSnapItem[]
 */
function getCommonSnapItems(): array
{
    $pickUp = new ObjPickupItem(
        x: 1744,
        y: 1072,
        type: 2,
        subType: 3,
    );

    $character = new ObjCharacterItem(
        tick: 4959,
        x: 1584,
        y: 305,
        velX: 0,
        velY: 128,
        angle: 0,
        direction: 0,
        jumped: 0,
        hookedPlayer: -1,
        hookState: 0,
        hookTick: 0,
        hookX: 1584,
        hookY: 304,
        hookDx: 0,
        hookDy: 0,
        playerFlags: 0,
        health: 10,
        armor: 0,
        ammoCount: 10,
        weapon: 1,
        emote: 0,
        attackTick: 0,
    );

    $gameInfo = new ObjGameInfoItem(
        gameFlags: 0,
        gameStateFlags: 0,
        roundStartTick: 0,
        warmupTimer: 0,
        scoreLimit: 20,
        timeLimit: 0,
        roundNum: 0,
        roundCurrent: 1,
    );

    $clientInfo = new ObjClientInfoItem(
        name: 'kaka',
        clan: 'kj',
        country: -1,
        skinName: 'default',
        useCustomColor: false,
        colorBody: 65408,
        colorFoot: 65408,
    );

    $playerInfo = new ObjPlayerInfoItem(
        local: true,
        clientId: 0,
        team: 0,
        score: 0,
        latency: 0,
    );

    return [
        $pickUp->setId(0),
        $character->setId(0),
        $gameInfo->setId(0),
        $clientInfo->setId(0),
        $playerInfo->setId(0),
    ];
}

test('can encode a full packet with multiple snap items', function () {
    [
        $pickUp,
        $character,
        $gameInfo,
        $clientInfo,
        $playerInfo,
    ] = getCommonSnapItems();

    $snapSingle = new SnapSingleChunk(
        currentTick: 4970,
        deltaTick: 4971,
        crc: 925235088,
        size: 133,
        snapPayload: [
            ...NetworkBase::packInt(0), // Removed Items
            ...NetworkBase::packInt(5), // Updated Items
            ...NetworkBase::packInt(0), // Unused
            ...$pickUp->encode(),
            ...$character->encode(),
            ...$gameInfo->encode(),
            ...$clientInfo->encode(),
            ...$playerInfo->encode(),
        ],
    );

    $encodedPacket = [
        0, // Flags
        6, // Ack
        1, // Num Chunks
        ...$snapSingle->encode(),
    ];

    expect($encodedPacket)->toBe(getExpectedPacket());
});

test('can encode a full packet with multiple snap items through snap handler', function () {
    $connectionClass = new class extends AbstractConnection
    {
        protected function handlePacketSending(AbstractPacket $packet): bool
        {
            throw new \Exception($packet->encodeToSend());
        }

        protected function handleUnsupportedChunk(UnsupportedChunk $chunk): void
        {
        }

        protected function handleConnectionOutOfSequence(int $sequence, int $ack): void
        {
        }
    };

    $connectionClass->ack = 6;

    try {
        $connectionClass->snaps()->sendItems(4970, getCommonSnapItems());
    } catch (\Exception $e) {
        $encodedPacket = NetworkBase::unpackBuffer($e->getMessage());
    }

    if (! isset($encodedPacket)) {
        throw new \Exception('Packet was not sent');
    }

    expect($encodedPacket)->toBe(getExpectedPacket());
});
