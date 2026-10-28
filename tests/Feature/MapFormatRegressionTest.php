<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapBufferReader;
use TeeFrame\Map\MapItems\EnvelopeItem;
use TeeFrame\Map\MapItems\GroupItem;
use TeeFrame\Map\MapItems\ImageItem;
use TeeFrame\Map\MapItems\InfoItem;
use TeeFrame\Map\MapLayers\TileLayer;
use TeeFrame\Map\MapReader;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\MapDataChunk;
use TeeFrame\Network\Chunks\System\RequestMapDataChunk;
use TeeFrame\Network\Packets\AbstractPacket;
use TeeFrame\Network\Packets\DefaultPacket;
use TeeFrame\Server\Concerns\HasHandshakeHandler;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Sockets\AbstractSocket;

/** @param int[] $integers */
function mapRegressionInts(array $integers): string
{
    return pack('V*', ...$integers);
}

/**
 * @param  array<int, array{type: int, id: int, data: string}>  $items
 * @param  string[]  $blocks
 */
function mapRegressionDatafile(int $version, array $items, array $blocks): string
{
    $types       = [];
    $itemOffsets = [];
    $itemBytes   = '';
    foreach ($items as $index => $item) {
        if (! isset($types[$item['type']])) {
            $types[$item['type']] = ['start' => $index, 'num' => 0];
        }
        $types[$item['type']]['num']++;
        $itemOffsets[] = strlen($itemBytes);
        $itemBytes .= mapRegressionInts([($item['type'] << 16) | $item['id'], strlen($item['data'])]).$item['data'];
    }

    $typeBytes = '';
    foreach ($types as $id => $type) {
        $typeBytes .= mapRegressionInts([$id, $type['start'], $type['num']]);
    }
    $dataOffsets = [];
    $dataSizes   = [];
    $dataBytes   = '';
    foreach ($blocks as $block) {
        $dataOffsets[] = strlen($dataBytes);
        $dataSizes[]   = strlen($block);
        $stored        = $version === 4 ? gzcompress($block) : $block;
        assert(is_string($stored));
        $dataBytes .= $stored;
    }
    $tables = $typeBytes.mapRegressionInts($itemOffsets).mapRegressionInts($dataOffsets);
    if ($version === 4) {
        $tables .= mapRegressionInts($dataSizes);
    }
    $dataStart = 36 + strlen($tables) + strlen($itemBytes);

    return 'DATA'.mapRegressionInts([
        $version, $dataStart + strlen($dataBytes) - 16, $dataStart - 16,
        count($types), count($items), count($blocks), strlen($itemBytes), strlen($dataBytes),
    ]).$tables.$itemBytes.$dataBytes;
}

function mapRegressionGameFile(string $tiles, int $width = 3, int $height = 3): string
{
    return mapRegressionDatafile(4, [
        ['type' => 0, 'id' => 0, 'data' => mapRegressionInts([1])],
        ['type' => 4, 'id' => 0, 'data' => mapRegressionInts([1, 0, 0, 100, 100, 0, 1])],
        ['type' => 5, 'id' => 0, 'data' => mapRegressionInts([
            0, 2, 0, 2, $width, $height, 1, 255, 255, 255, 255, -1, 0, -1, 0,
        ])],
    ], [$tiles]);
}

function mapRegressionRead(string $bytes): MapReader
{
    $path = tempnam(sys_get_temp_dir(), 'teeframe-map-');
    assert(is_string($path));
    try {
        file_put_contents($path, $bytes);
        $reader = new MapReader($path);
        $reader->read();

        return $reader;
    } finally {
        unlink($path);
    }
}

function mapRegressionLoad(string $bytes): Map
{
    $path = tempnam(sys_get_temp_dir(), 'teeframe-map-');
    assert(is_string($path));
    try {
        file_put_contents($path, $bytes);

        return new Map($path);
    } finally {
        unlink($path);
    }
}

test('failed map reads throw without warnings and restore the previous error handler', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-map-');
    assert(is_string($path));
    unlink($path);

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        expect(fn () => (new MapReader($path))->read())
            ->toThrow(RuntimeException::class, "{$path} : Failed to read file");
        expect($warnings)->toBe([]);
        trigger_error('restored handler', E_USER_WARNING);
        expect($warnings)->toBe(['restored handler']);
    } finally {
        restore_error_handler();
    }
});

test('map integers retain signed 32 bit values', function () {
    $reader = new MapBufferReader(mapRegressionInts([0, 2147483647, -1, -2147483648, -256]));
    expect($reader->readInts(5))->toBe([0, 2147483647, -1, -2147483648, -256]);
});

test('map buffer rejects truncated and negative reads', function () {
    expect(fn () => (new MapBufferReader("\0\0"))->readInt())->toThrow(RuntimeException::class);
    expect(fn () => (new MapBufferReader('abc'))->readBytes(-1))->toThrow(RuntimeException::class);
    expect(fn () => (new MapBufferReader('abc'))->readInts(1))->toThrow(RuntimeException::class);
    expect(fn () => (new MapBufferReader('abc'))->readInts(-1))->toThrow(RuntimeException::class);
    expect(fn () => (new MapBufferReader('abc'))->readString())->toThrow(RuntimeException::class);
    expect((new MapBufferReader("abc\0"))->readString())->toBe('abc');
});

test('packed map names decode byte order padding and final terminator', function () {
    $game = mapRegressionInts([0xC7E1EDE5, 0x80808080, 0x80808000]);
    expect(GroupItem::readI32String(new MapBufferReader($game), 3))->toBe('Game');
    $full = mapRegressionInts([0xC8E5ECEC, 0xEFA0D7EF, 0xF2ECE400]);
    expect(GroupItem::readI32String(new MapBufferReader($full), 3))->toBe('Hello World');
    expect(GroupItem::readI32String(new MapBufferReader(mapRegressionInts([0x80808000])), 1))->toBe('');
    // UTF-8 bytes C3 A9 become 43 29 after wrapping +128.
    expect(GroupItem::readI32String(new MapBufferReader(mapRegressionInts([0x43298000])), 1))->toBe('é');
});

test('group offsets and packed names decode together', function () {
    $data  = mapRegressionInts([3, -256, -52, 30, 30, 0, 1, 0, 0, 0, 0, 0, 0xC7E1EDE5, 0x80808080, 0x80808000]);
    $group = GroupItem::make(0, strlen($data), $data);
    expect($group->offsetX)->toBe(-256)->and($group->offsetY)->toBe(-52)->and($group->name)->toBe('Game');
});

test('info metadata is parsed as optional data block indices', function () {
    $bytes  = mapRegressionInts([1, 0, -1, 1, -1]);
    $info   = InfoItem::make(0, strlen($bytes), $bytes);
    $reader = mapRegressionRead(mapRegressionDatafile(4, [
        ['type' => 1, 'id' => 0, 'data' => $bytes],
    ], ["Author\0", "Credits\0"]));
    expect($info->author)->toBe(0)->and($info->version)->toBe(-1)->and($info->credits)->toBe(1);
    expect($reader->getDataString($info->author))->toBe('Author');
    expect($reader->getDataString($info->version))->toBe('');
});

test('image version one defaults to rgba and version two reads its format', function () {
    $v1 = mapRegressionInts([1, 1, 1, 0, 0, 1]);
    $v2 = mapRegressionInts([2, 1, 1, 0, 0, 1, 0]);
    expect(ImageItem::make(0, strlen($v1), $v1)->format)->toBe(1);
    expect(ImageItem::make(0, strlen($v2), $v2)->format)->toBe(0);
});

test('legacy envelope without a name remains readable', function () {
    $bytes    = mapRegressionInts([1, 3, 0, 2]);
    $envelope = EnvelopeItem::make(0, strlen($bytes), $bytes);
    expect($envelope->name)->toBe('')->and($envelope->synchronized)->toBeTrue();
});

test('version three and four datafiles yield the same blocks', function (int $version) {
    $reader = mapRegressionRead(mapRegressionDatafile($version, [
        ['type' => 0, 'id' => 0, 'data' => mapRegressionInts([1])],
    ], ["hello\0", '', "\x00\xFF"]));
    expect($reader->getDataBlocks())->toBe(["hello\0", '', "\x00\xFF"]);
    expect($reader->getItems()[0]['data'])->toBe(mapRegressionInts([1]));
})->with([3, 4]);

test('reversed datafile magic is accepted without changing integer endianness', function () {
    $bytes = mapRegressionDatafile(4, [], ["hello\0"]);
    $bytes = substr_replace($bytes, 'ATAD', 0, 4);
    expect(mapRegressionRead($bytes)->getDataString(0))->toBe('hello');
});

test('invalid datafile tables and headers are rejected', function (int $offset, int $value) {
    $bytes = mapRegressionDatafile(4, [
        ['type' => 0, 'id' => 0, 'data' => mapRegressionInts([1])],
    ], ["hello\0"]);
    $bytes = substr_replace($bytes, mapRegressionInts([$value]), $offset, 4);
    expect(fn () => mapRegressionRead($bytes))->toThrow(RuntimeException::class);
})->with([
    'negative count'             => [16, -1],
    'incorrect file size'        => [8, 0],
    'incorrect swap length'      => [12, 0],
    'invalid item type range'    => [40, 1],
    'nonzero first item offset'  => [48, 4],
    'out of range data offset'   => [52, 10000],
    'wrong uncompressed size'    => [56, 2],
    'negative uncompressed size' => [56, -1],
    'wrong item type'            => [60, 1 << 16],
    'wrong item payload size'    => [64, 8],
]);

test('truncated and invalid zlib data are rejected', function () {
    $bytes = mapRegressionDatafile(4, [], ['hello']);
    expect(fn () => mapRegressionRead(substr($bytes, 0, -1)))->toThrow(RuntimeException::class);
    $bytes[strlen($bytes) - 1] = chr((ord($bytes[strlen($bytes) - 1]) ^ 0xFF) & 0xFF);
    expect(fn () => mapRegressionRead($bytes))->toThrow(RuntimeException::class);
});

test('duplicate item ids and overlapping offsets are rejected', function () {
    $items = [
        ['type' => 0, 'id' => 0, 'data' => mapRegressionInts([1])],
        ['type' => 0, 'id' => 0, 'data' => mapRegressionInts([1])],
    ];
    expect(fn () => mapRegressionRead(mapRegressionDatafile(4, $items, [])))->toThrow(RuntimeException::class);
    $items[1]['id'] = 1;
    $bytes          = mapRegressionDatafile(4, $items, []);
    $bytes          = substr_replace($bytes, mapRegressionInts([0]), 52, 4);
    expect(fn () => mapRegressionRead($bytes))->toThrow(RuntimeException::class);
});

test('tile grids reject invalid dimensions counts and incomplete records', function () {
    expect(fn () => new TileLayer(0, 1, ''))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(-1, 1, ''))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(1, 1, "\0\0\0"))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(2, 1, str_repeat("\0", 4)))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(1, 1, str_repeat("\0", 8)))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(1, 1, "\0\0\x02\0", 4))->toThrow(RuntimeException::class);
    expect(fn () => new TileLayer(2, 1, str_repeat("\0", 4), 4))->toThrow(RuntimeException::class);
});

test('version three tiles do not expand rendering skip counts', function () {
    $layer = new TileLayer(2, 1, "\x01\0\x03\0\x02\0\0\0");
    expect(count($layer->getTiles()))->toBe(2)->and($layer->getTileIndex(1, 0))->toBe(2);
});

test('map reload replaces previous groups layers and reader data', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-map-');
    assert(is_string($path));
    try {
        file_put_contents($path, mapRegressionGameFile(str_repeat("\0", 36)));
        $map    = new Map($path);
        $reader = new MapReader($path);
        $reader->read();
        $reader->read();
        $map->load($path);
        expect(count($reader->getItems()))->toBe(3);
        expect(count($map->getGroups()))->toBe(1)->and(count($map->getLayers()))->toBe(1);
    } finally {
        unlink($path);
    }
});

test('character dies when a sampled corner touches a death tile', function () {
    $tiles                  = str_repeat("\0", 36);
    $tiles[4 * (1 * 3 + 1)] = "\x02";
    $map                    = mapRegressionLoad(mapRegressionGameFile($tiles));
    $world                  = createWorld($map);
    // Center is in air; its right corners sample the death tile at x=32.
    $position  = new Vector2(24, 48);
    $character = new PvpCharacterEntity($world, $position);
    $tee       = new PlayerTee;
    $character->spawn($position, $tee);
    $character->doTick();
    expect($character->alive)->toBeFalse()->and($character->isToDestroy())->toBeTrue();
    expect($tee->character)->toBeNull()->and($tee->spawning)->toBeTrue();
});

test('game layer clipping uses rounded truncated tile coordinates and a 200 tile margin', function () {
    $world     = createWorld(mapRegressionLoad(mapRegressionGameFile(str_repeat("\0", 36))));
    $character = new PvpCharacterEntity($world, new Vector2(48, 48));
    foreach ([new Vector2(-6400, 48), new Vector2(203 * 32, 48), new Vector2(48, -6400)] as $position) {
        expect($character->gameLayerClipped($position))->toBeFalse();
    }
    foreach ([new Vector2(-6432, 48), new Vector2(204 * 32, 48), new Vector2(48, -6432), new Vector2(48, 204 * 32)] as $position) {
        expect($character->gameLayerClipped($position))->toBeTrue();
    }
    $position = new Vector2(204 * 32, 48);
    $character->spawn($position, new PlayerTee);
    $character->doTick();
    expect($character->alive)->toBeFalse();
});

test('projectiles are destroyed outside the game layer clipping margin', function () {
    $world      = createWorld(mapRegressionLoad(mapRegressionGameFile(str_repeat("\0", 36))));
    $projectile = new PvpProjectileEntity($world, new Vector2(204 * 32, 48), new Vector2(1, 0), GameConstants::WEAPON_GUN);
    $projectile->setTuning(2200.0, 1.25, 100);
    $projectile->doTick();
    expect($projectile->isToDestroy())->toBeTrue();
});

test('flags return when they leave the game layer clipping margin', function () {
    $tiles      = str_repeat("\0", 36);
    $tiles[16]  = chr(195);
    $map        = mapRegressionLoad(mapRegressionGameFile($tiles));
    $ticks      = new TickHandler;
    $controller = new class($ticks, true, true) extends AbstractGameController {};
    $world      = new TestWorldWithController($ticks, $map, $controller);
    $flag       = $controller->getFlag(GameConstants::TEAM_RED);
    assert($flag !== null);
    $flag->setPosition(new Vector2(204 * 32, 48));
    $method = new ReflectionMethod($controller, 'tickFlags');
    $method->invoke($controller);
    expect($flag->getPosition()->x)->toBe(48.0)->and($flag->atStand)->toBeTrue();
});

test('map downloads mark only the final data chunk as last', function (int $mapSize) {
    $headerSize = strlen(mapRegressionDatafile(3, [], ['']));
    $bytes      = mapRegressionDatafile(3, [], [str_repeat("\xA5", $mapSize - $headerSize)]);
    $map        = mapRegressionLoad($bytes);
    expect($map->getSize())->toBe($mapSize);

    $socket = new class extends AbstractSocket
    {
        public function __construct() {}
    };
    $connection = new class($socket, createWorld($map)) extends ConnectionSlot
    {
        /** @var AbstractPacket[] */
        public array $sentPackets = [];

        protected function handlePacketSending(AbstractPacket $packet): bool
        {
            $this->sentPackets[] = $packet;

            return true;
        }
    };
    $handler = new class
    {
        use HasHandshakeHandler {
            handleRequestMapDataChunk as public requestMapData;
        }
    };

    $chunkCount = intdiv($mapSize + 895, 896);
    $received   = [];
    for ($index = 0; $index < $chunkCount; $index++) {
        expect($handler->requestMapData($connection, new RequestMapDataChunk($index)))->toBeTrue();
        expect($connection->sentPackets)->toHaveCount($index + 1);
        $packet = $connection->sentPackets[$index];
        expect($packet)->toBeInstanceOf(DefaultPacket::class);
        assert($packet instanceof DefaultPacket);
        expect($packet->getChunks())->toHaveCount(1);
        $chunk = $packet->getChunks()[0];
        expect($chunk)->toBeInstanceOf(MapDataChunk::class);
        assert($chunk instanceof MapDataChunk);

        expect($chunk->last)->toBe($index === $chunkCount - 1 ? 1 : 0);
        expect($chunk->crc)->toBe($map->getCrc());
        expect($chunk->chunk)->toBe($index);
        expect($chunk->chunkSize)->toBe(min(896, $mapSize - $index * 896));
        expect($chunk->data)->toBe(array_slice($map->getRawData(), $index * 896, $chunk->chunkSize));
        $received = [...$received, ...$chunk->data];
    }
    expect($received)->toBe($map->getRawData());
})->with([
    'short final chunk'       => 895,
    'one exact chunk'         => 896,
    'one byte in final chunk' => 897,
    'two exact chunks'        => 1792,
    'three exact chunks'      => 2688,
]);

test('map transfer payloads follow the documented zero six field order', function () {
    $change = (new MapChangeChunk('dm1', -233464210, 5805))->getPayload();
    expect($change->extractString())->toBe('dm1');
    expect($change->extractInt())->toBe(-233464210);
    expect($change->extractInt())->toBe(5805);
    $data = (new MapDataChunk(1, -233464210, 6, 3, [0, 128, 255]))->getPayload();
    expect($data->extractInt())->toBe(1);
    expect($data->extractInt())->toBe(-233464210);
    expect($data->extractInt())->toBe(6);
    expect($data->extractInt())->toBe(3);
    expect($data->extractBytes(3))->toBe([0, 128, 255]);
    expect((new RequestMapDataChunk(6))->getPayload()->extractInt())->toBe(6);
});
