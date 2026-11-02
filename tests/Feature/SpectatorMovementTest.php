<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjCharacterItem;
use TeeFrame\Network\SnapItems\ObjPickupItem;
use TeeFrame\Network\SnapItems\ObjSpectatorInfoItem;

/** @return array{TestWorld, TickHandler, PlayerTee, PlayerTee, PvpCharacterEntity} */
function spectatorMovementFixture(): array
{
    $clock = new TickHandler(100);
    $map   = new class extends Map
    {
        public function __construct()
        {
            $tiles                    = str_repeat("\0", 64 * 16 * 4);
            $tiles[4 * (8 * 64 + 12)] = chr(GameLayer::ENTITY_SPAWN);
            $this->gameLayer          = new GameLayer(64, 16, $tiles);
            $this->collision          = $this->createCollision($this->gameLayer);
        }
    };
    $world                               = new TestWorld($clock, $map);
    $world->getTuneController()->gravity = 0;
    $player                              = new PlayerTee;
    $spectator                           = new PlayerTee;
    $world->addTee($player);
    $world->addTee($spectator);
    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);
    $spectator->spectatorId = $player->teeIndex;
    $character              = new PvpCharacterEntity($world, new Vector2(128, 128));
    $character->spawn(new Vector2(128, 128), $player);
    $world->addEntity($character);

    return [$world, $clock, $player, $spectator, $character];
}

test('spectator follow snapshots use this ticks quantized character position', function (float $velocityX, float $velocityY, int $direction, int $gravity) {
    [$world, $clock, $player, $spectator, $character] = spectatorMovementFixture();
    $world->getTuneController()->gravity              = $gravity;
    $character->vel                                   = new Vector2($velocityX, $velocityY);
    $character->onPredictedInput(input(['direction' => $direction]));

    for ($i = 0; $i < 8; $i++) {
        $previousPosition = clone $character->getPosition();
        $world->doTick();

        expect($character->getPosition())->not->toEqual($previousPosition);
        expect($player->viewPosition)->toEqual($character->getPosition());
        expect($player->viewPosition)->not->toBe($character->getPosition());
        expect($spectator->viewPosition)->toEqual($character->getPosition());
        expect($spectator->viewPosition)->not->toBe($player->viewPosition);
        $snaps         = $world->doSnap($spectator);
        $characterInfo = array_find($snaps, fn ($item) => $item instanceof ObjCharacterItem);
        $spectatorInfo = array_find($snaps, fn ($item) => $item instanceof ObjSpectatorInfoItem);
        assert($characterInfo instanceof ObjCharacterItem && $spectatorInfo instanceof ObjSpectatorInfoItem);
        expect([$spectatorInfo->x, $spectatorInfo->y])->toBe([$characterInfo->x, $characterInfo->y]);
        $clock->advanceTick();
    }
})->with([
    'horizontal movement'              => [10.0, 0.0, 1, 0],
    'fractional movement with gravity' => [2.25, 1.125, 0, 50],
]);

test('spectator following includes server-driven ninja movement in the same tick', function () {
    [$world, , $player, $spectator, $character] = spectatorMovementFixture();
    $character->giveNinja();
    for ($i = 0; $i < 3; $i++) {
        $character->onPredictedInput(input(['targetX' => 100, 'targetY' => 0]));
    }
    $player->onDirectInput(input(['targetX' => 100, 'targetY' => 0, 'fire' => 1]), $world->getCurrentTick());

    $world->doTick();

    expect($character->getPosition()->x)->toBe(178.0);
    expect($player->viewPosition)->toEqual($character->getPosition());
    expect($spectator->viewPosition)->toEqual($character->getPosition());
});

test('spectator following remains frozen while paused and resumes with the current movement tick', function () {
    [$world, $clock, $player, $spectator, $character] = spectatorMovementFixture();
    $character->vel->x                                = 10;
    $character->onPredictedInput(input(['direction' => 1]));
    $world->doTick();
    $pausedPosition = clone $character->getPosition();
    $world->setPaused(true);
    $clock->advanceTick();

    $world->doTick();

    expect($character->getPosition())->toEqual($pausedPosition);
    expect($player->viewPosition)->toEqual($pausedPosition);
    expect($spectator->viewPosition)->toEqual($pausedPosition);
    $world->setPaused(false);
    $clock->advanceTick();
    $world->doTick();
    expect($character->getPosition()->x)->toBeGreaterThan($pausedPosition->x);
    expect($player->viewPosition)->toEqual($character->getPosition());
    expect($spectator->viewPosition)->toEqual($character->getPosition());
});

test('spectator following uses the respawn position before the new characters first tick', function () {
    [$world, $clock, $player, $spectator, $character] = spectatorMovementFixture();
    $character->die();
    while ($clock->getCurrentTick() < $player->respawnTick) {
        $clock->advanceTick();
    }

    $world->doTick();

    $respawned = $player->character;
    assert($respawned instanceof PvpCharacterEntity);
    expect($respawned)->not->toBe($character);
    expect($respawned->tick)->toBe(0);
    expect($respawned->getPosition())->toEqual(new Vector2(400, 272));
    expect($player->viewPosition)->toEqual($respawned->getPosition());
    expect($spectator->viewPosition)->toEqual($respawned->getPosition());
});

test('snapshot distance culling uses the players position after movement', function () {
    [$world, , $player, , $character] = spectatorMovementFixture();
    $character->vel->x                = 10;
    $character->onPredictedInput(input(['direction' => 1]));
    $world->addEntity(new PickupEntity($world, new Vector2(1237, 128), GameConstants::POWERUP_HEALTH));

    $world->doTick();

    expect($character->getPosition()->x)->toBe(138.0);
    $pickup = array_find($world->doSnap($player), fn ($item) => $item instanceof ObjPickupItem);
    expect($pickup)->toBeInstanceOf(ObjPickupItem::class);
    assert($pickup instanceof ObjPickupItem);
    expect([$pickup->x, $pickup->y])->toBe([1237, 128]);
});
