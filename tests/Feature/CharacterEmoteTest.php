<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

/** @return array{TestWorld, TickHandler, PlayerTee, PvpCharacterEntity} */
function createCharacterEmoteWorld(): array
{
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $tee);
    $world->addEntity($character);

    return [$world, $clock, $tee, $character];
}

function characterSnapshotEmote(PvpCharacterEntity $character): int
{
    $snap = $character->doSnap(new PlayerTee)[0];
    assert($snap instanceof ObjCharacterItem);

    return $snap->emote;
}

test('a temporary character emote expires after its deadline tick', function () {
    [, $clock, , $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY, 101);
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_HAPPY);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_HAPPY);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
    expect($character->emote)->toBe(GameConstants::EMOTE_NORMAL);
});

test('replacing an emote also replaces its expiration deadline', function () {
    [, $clock, , $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY, 101);
    $clock->next();
    $character->setEmote(GameConstants::EMOTE_ANGRY, 103);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_ANGRY);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_ANGRY);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
});

test('paused time preserves the remaining duration of a character emote', function () {
    [$world, $clock, , $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY, 102);
    $world->setPaused(true);
    for ($i = 0; $i < 20; $i++) {
        $world->doTick();
        $clock->next();
        expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_HAPPY);
    }

    $world->setPaused(false);
    for ($i = 0; $i < 2; $i++) {
        $world->doTick();
        $clock->next();
        expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_HAPPY);
    }
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
});

test('chat-selected expressions do not expire after the previous duration limit', function (string $name, int $emote) {
    [$world, $clock, $tee, $character] = createCharacterEmoteWorld();
    $world->onMessage($tee, new ClSayChunk(false, '/emote '.$name));
    expect(characterSnapshotEmote($character))->toBe($emote);
    for ($i = 0; $i <= 60 * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->next();
    }
    expect(characterSnapshotEmote($character))->toBe($emote);
})->with([
    ['normal', GameConstants::EMOTE_NORMAL],
    ['pain', GameConstants::EMOTE_PAIN],
    ['happy', GameConstants::EMOTE_HAPPY],
    ['surprise', GameConstants::EMOTE_SURPRISE],
    ['angry', GameConstants::EMOTE_ANGRY],
    ['blink', GameConstants::EMOTE_BLINK],
]);

test('a permanent expression can be changed back to normal through chat', function () {
    [$world, $clock, $tee, $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY);
    $world->onMessage($tee, new ClSayChunk(false, '/emote normal'));
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
});

test('an explicit emote duration replaces a permanent expression and expires at its deadline', function (int $seconds) {
    [$world, $clock, $tee, $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY);
    $world->onMessage($tee, new ClSayChunk(false, '/emote angry '.$seconds));
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_ANGRY);
    for ($i = 0; $i < $seconds * NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->next();
    }
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_ANGRY);
    $clock->next();
    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
})->with([1, 10, 60]);

test('invalid emote durations leave the current expression unchanged', function (int $seconds) {
    [$world, , $tee, $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY);
    recordingServer($world)->clear();

    $world->onMessage($tee, new ClSayChunk(false, '/emote angry '.$seconds));

    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_HAPPY);
    $chunks = recordingServer($world)->sentChunks;
    expect($chunks)->toHaveCount(1);
    assert($chunks[0] instanceof SvChatChunk);
    expect($chunks[0]->text)->toBe('Duration must be between 1 and 60 seconds');
})->with([-1, 0, 61, PHP_INT_MAX]);

test('spawning a reused character clears its previous emote', function () {
    [, , $tee, $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY, 200);

    $character->spawn(new Vector2(64, 64), $tee);

    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
});
