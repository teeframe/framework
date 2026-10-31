<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
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

test('spawning a reused character clears its previous emote', function () {
    [, , $tee, $character] = createCharacterEmoteWorld();
    $character->setEmote(GameConstants::EMOTE_HAPPY, 200);

    $character->spawn(new Vector2(64, 64), $tee);

    expect(characterSnapshotEmote($character))->toBe(GameConstants::EMOTE_NORMAL);
});
