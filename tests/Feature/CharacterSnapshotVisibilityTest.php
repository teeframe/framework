<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\SnapItems\ObjCharacterItem;

test('character combat stats are visible only to the owner and following spectators by default', function (string $recipient, bool $visible) {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $owner  = new PlayerTee;
    $viewer = new PlayerTee;
    $other  = new PlayerTee;
    foreach ([$owner, $viewer, $other] as $tee) {
        $world->addTee($tee);
    }
    $owner->team  = GameConstants::TEAM_RED;
    $viewer->team = $recipient === 'opponent' ? GameConstants::TEAM_BLUE : GameConstants::TEAM_RED;
    if (str_starts_with($recipient, 'spectator')) {
        $viewer->team = GameConstants::TEAM_SPECTATORS;
    }
    $viewer->spectatorId = match ($recipient) {
        'spectator following owner', 'playing with spectator id' => $owner->teeIndex,
        'spectator following another player'                     => $other->teeIndex,
        default                                                  => GameConstants::SPEC_FREEVIEW,
    };
    $character = new PvpCharacterEntity($world, new Vector2(128, 96));
    $character->spawn(new Vector2(128, 96), $owner);
    $character->health                                   = 7;
    $character->armor                                    = 4;
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 9;

    $snap = $character->doSnap($recipient === 'owner' ? $owner : $viewer)[0];

    expect($snap)->toBeInstanceOf(ObjCharacterItem::class);
    assert($snap instanceof ObjCharacterItem);
    expect([$snap->health, $snap->armor, $snap->ammoCount])->toBe($visible ? [7, 4, 9] : [0, 0, 0]);
    expect([$snap->x, $snap->y, $snap->weapon])->toBe([128, 96, GameConstants::WEAPON_GUN]);
})->with([
    'owner'                             => ['owner', true],
    'opponent'                          => ['opponent', false],
    'teammate'                          => ['teammate', false],
    'free-view spectator'               => ['spectator in free view', false],
    'following spectator'               => ['spectator following owner', true],
    'spectator following someone else'  => ['spectator following another player', false],
    'non-spectator with a spectator id' => ['playing with spectator id', false],
]);

test('unlimited ammunition is sent as zero even when combat stats are visible', function (int $weapon) {
    $world = new TestWorld(new TickHandler(100), new Map);
    $owner = new PlayerTee;
    $world->addTee($owner);
    $character = new PvpCharacterEntity($world, new Vector2(128, 96));
    $character->spawn(new Vector2(128, 96), $owner);
    if ($weapon === GameConstants::WEAPON_NINJA) {
        $character->giveNinja();
    } else {
        $character->activeWeapon = $weapon;
    }

    $snap = $character->doSnap($owner)[0];

    assert($snap instanceof ObjCharacterItem);
    expect($snap->health)->toBe(10);
    expect($snap->ammoCount)->toBe(0);
})->with([GameConstants::WEAPON_HAMMER, GameConstants::WEAPON_NINJA]);

test('mods can override character stat visibility', function () {
    $world  = new TestWorld(new TickHandler(100), new Map);
    $owner  = new PlayerTee;
    $viewer = new PlayerTee;
    $world->addTee($owner);
    $world->addTee($viewer);
    $character = new class($world, new Vector2(128, 96)) extends PvpCharacterEntity
    {
        protected function canViewStats(AbstractTee $requestingTee): bool
        {
            return true;
        }
    };
    $character->spawn(new Vector2(128, 96), $owner);
    $character->health                                   = 7;
    $character->armor                                    = 4;
    $character->weapons[GameConstants::WEAPON_GUN]->ammo = 9;

    $snap = $character->doSnap($viewer)[0];

    assert($snap instanceof ObjCharacterItem);
    expect([$snap->health, $snap->armor, $snap->ammoCount])->toBe([7, 4, 9]);
});
