<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;

/** @return array{TestWorldWithController, TickHandler, PvpCharacterEntity, PvpCharacterEntity, PlayerTee, PlayerTee} */
function createDamageFeedbackWorld(string $mode = 'DM'): array
{
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock);
    $controller->setGameMode($mode);
    $world  = new TestWorldWithController($clock, new Map, $controller);
    $owner  = new PlayerTee;
    $target = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($target);
    $attacker = new PvpCharacterEntity($world, new Vector2(64, 64));
    $attacker->spawn(new Vector2(64, 64), $owner);
    $world->addEntity($attacker);
    $victim = new PvpCharacterEntity($world, new Vector2(192, 64));
    $victim->spawn(new Vector2(192, 64), $target);
    $world->addEntity($victim);
    $world->clearEvents();
    recordingServer($world)->clear();

    return [$world, $clock, $attacker, $victim, $owner, $target];
}

function damageFeedbackEmote(PvpCharacterEntity $character, PlayerTee $viewer): int
{
    $snap = $character->doSnap($viewer)[0];
    assert($snap instanceof ObjCharacterItem);

    return $snap->emote;
}

test('surviving hits use post-armor damage for pain sounds and set a half-second pain expression', function (int $damage, int $armor, int $healthAfter, int $armorAfter, int $sound) {
    [$world, $clock, $attacker, $victim, $owner, $target] = createDamageFeedbackWorld();
    $victim->armor                                        = $armor;

    $victim->takeDamage(new Vector2(0, 0), $damage, $owner->teeIndex, GameConstants::WEAPON_RIFLE);

    expect($victim->alive)->toBeTrue();
    expect([$victim->health, $victim->armor])->toBe([$healthAfter, $armorAfter]);
    $sounds = array_values(array_filter($world->doSnap($target), fn ($item) => $item instanceof ObjEventSoundWorldItem));
    expect($sounds)->toHaveCount(1);
    expect($sounds[0]->getInts())->toBe([192, 64, $sound]);
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe([GameConstants::SOUND_HIT]);
    expect($attacker->emote)->toBe(GameConstants::EMOTE_NORMAL);
    expect(damageFeedbackEmote($victim, $target))->toBe(GameConstants::EMOTE_PAIN);

    for ($i = 0; $i < intdiv(NetworkParams::TICKS_PER_SECOND, 2); $i++) {
        $clock->next();
    }
    expect(damageFeedbackEmote($victim, $target))->toBe(GameConstants::EMOTE_PAIN);
    $clock->next();
    expect(damageFeedbackEmote($victim, $target))->toBe(GameConstants::EMOTE_NORMAL);
})->with([
    'armor absorbs everything'         => [1, 1, 10, 0, GameConstants::SOUND_PLAYER_PAIN_SHORT],
    'short pain'                       => [2, 0, 8, 0, GameConstants::SOUND_PLAYER_PAIN_SHORT],
    'long pain'                        => [3, 0, 7, 0, GameConstants::SOUND_PLAYER_PAIN_LONG],
    'armor reduces long pain to short' => [5, 2, 7, 0, GameConstants::SOUND_PLAYER_PAIN_SHORT],
    'armor leaves long pain'           => [6, 2, 6, 0, GameConstants::SOUND_PLAYER_PAIN_LONG],
]);

test('lethal hits emit death without pain and give the killer a one-second happy expression', function (int $health, int $armor, int $damage) {
    [$world, $clock, $attacker, $victim, $owner, $target] = createDamageFeedbackWorld();
    $victim->health                                       = $health;
    $victim->armor                                        = $armor;

    $victim->takeDamage(new Vector2(0, 0), $damage, $owner->teeIndex, GameConstants::WEAPON_RIFLE);

    expect($victim->alive)->toBeFalse();
    $sounds = array_values(array_filter($world->doSnap($target), fn ($item) => $item instanceof ObjEventSoundWorldItem));
    expect(array_map(fn ($sound) => $sound->soundId, $sounds))->toBe([GameConstants::SOUND_PLAYER_DIE]);
    expect($victim->emote)->toBe(GameConstants::EMOTE_NORMAL);
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe([GameConstants::SOUND_HIT]);
    expect(damageFeedbackEmote($attacker, $owner))->toBe(GameConstants::EMOTE_HAPPY);

    for ($i = 0; $i < NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->next();
    }
    expect(damageFeedbackEmote($attacker, $owner))->toBe(GameConstants::EMOTE_HAPPY);
    $clock->next();
    expect(damageFeedbackEmote($attacker, $owner))->toBe(GameConstants::EMOTE_NORMAL);
})->with([
    'unarmored death'    => [1, 0, 1],
    'armor bypass kills' => [1, 10, 2],
]);

test('hit confirmation reaches only the attacker and spectators following that attacker', function (bool $lethal, bool $attackerAlive) {
    [$world, , $attacker, $victim, $owner, $target] = createDamageFeedbackWorld();
    $followers                                      = [];
    for ($i = 0; $i < 2; $i++) {
        $follower = new PlayerTee;
        $world->addTee($follower);
        $follower->team        = GameConstants::TEAM_SPECTATORS;
        $follower->spectatorId = $owner->teeIndex;
        $followers[]           = $follower;
    }
    $freeview = new PlayerTee;
    $world->addTee($freeview);
    $freeview->team = GameConstants::TEAM_SPECTATORS;
    $victimFollower = new PlayerTee;
    $world->addTee($victimFollower);
    $victimFollower->team        = GameConstants::TEAM_SPECTATORS;
    $victimFollower->spectatorId = $target->teeIndex;
    $otherPlayer                 = new PlayerTee;
    $world->addTee($otherPlayer);
    $otherPlayer->spectatorId = $owner->teeIndex;
    if (! $attackerAlive) {
        $attacker->die();
        $world->removeEntity($attacker);
    }
    $victim->health = $lethal ? 1 : 10;
    $world->clearEvents();
    recordingServer($world)->clear();

    $victim->takeDamage(new Vector2(0, 0), 1, $owner->teeIndex, GameConstants::WEAPON_GUN);

    foreach ([$owner, ...$followers] as $listener) {
        expect(soundsSentToTee($world, $listener->teeIndex))->toBe([GameConstants::SOUND_HIT]);
    }
    foreach ([$target, $freeview, $victimFollower, $otherPlayer] as $listener) {
        expect(soundsSentToTee($world, $listener->teeIndex))->toBeEmpty();
    }
    expect($attacker->emote)->toBe($lethal && $attackerAlive ? GameConstants::EMOTE_HAPPY : GameConstants::EMOTE_NORMAL);
})->with([false, true])->with([false, true]);

test('self and world damage do not produce hit confirmation or a happy killer', function (bool $self, bool $lethal) {
    [$world, , $attacker, $victim, $owner, $target] = createDamageFeedbackWorld();
    $victim->health                                 = $lethal ? 1 : 10;
    $victim->takeDamage(new Vector2(0, 0), 1, $self ? $target->teeIndex : -1, GameConstants::WEAPON_GRENADE);

    expect(soundsSentToTee($world, $owner->teeIndex))->toBeEmpty();
    expect(soundsSentToTee($world, $target->teeIndex))->toBeEmpty();
    expect($attacker->emote)->toBe(GameConstants::EMOTE_NORMAL);
    expect($victim->emote)->toBe($lethal ? GameConstants::EMOTE_NORMAL : GameConstants::EMOTE_PAIN);
})->with([false, true])->with([false, true]);

test('ignored damage does not produce hit confirmation pain or facial reactions', function (string $reason) {
    [$world, , $attacker, $victim, $owner, $target] = createDamageFeedbackWorld($reason === 'friendly fire' ? 'TDM' : 'DM');
    if ($reason === 'dead victim') {
        $victim->die();
    } elseif ($reason === 'friendly fire') {
        $target->team = $owner->team;
    }
    $world->clearEvents();
    recordingServer($world)->clear();

    $victim->takeDamage(new Vector2(1, 0), $reason === 'zero damage' ? 0 : 1, $owner->teeIndex, GameConstants::WEAPON_GUN);

    expect(soundsSentToTee($world, $owner->teeIndex))->toBeEmpty();
    expect(array_filter($world->doSnap($target), fn ($item) => $item instanceof ObjEventSoundWorldItem))->toBeEmpty();
    expect($attacker->emote)->toBe(GameConstants::EMOTE_NORMAL);
    expect($victim->emote)->toBe(GameConstants::EMOTE_NORMAL);
})->with(['zero damage', 'friendly fire', 'dead victim']);
