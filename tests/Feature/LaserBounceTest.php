<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjLaserItem;

/** @return array{TestWorld, TickHandler, PlayerTee} */
function createLaserBounceWorld(): array
{
    $map = new class extends Map
    {
        public function __construct()
        {
            $tiles = '';
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 64; $x++) {
                    $tiles .= pack('C4', $x === 8 ? GameLayer::TILE_SOLID : GameLayer::TILE_AIR, 0, 0, 0);
                }
            }
            $this->gameLayer = new GameLayer(64, 16, $tiles);
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, $map);
    $owner = new PlayerTee;
    $world->addTee($owner);

    return [$world, $clock, $owner];
}

test('a laser reflects outside the wall and keeps its first segment for the snapshot', function () {
    [$world, , $owner] = createLaserBounceWorld();
    $laser             = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    $world->addEntity($laser);

    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect($laser->isToDestroy())->toBeFalse();
    expect([$snap->fromX, $snap->fromY, $snap->x, $snap->y, $snap->startTick])->toBe([64, 64, 255, 64, 100]);
    expect($laser->direction->x)->toBe(-1.0);
    expect($laser->direction->y)->toEqual(0.0);
    $collision = $world->getMap()->getCollision();
    assert($collision !== null);
    expect($collision->checkPoint($laser->getPosition()->x, $laser->getPosition()->y))->toBeFalse();

    $sounds = array_values(array_filter($world->doSnap($owner), fn ($item) => $item instanceof ObjEventSoundWorldItem));
    expect($sounds)->toHaveCount(1);
    expect($sounds[0]->getInts())->toBe([255, 64, GameConstants::SOUND_RIFLE_BOUNCE]);
});

test('laser segments advance only after the configured bounce delay', function (int $delayMs) {
    [$world, $clock, $owner]                      = createLaserBounceWorld();
    $world->getTuneController()->laserBounceDelay = $delayMs * 100;
    $laser                                        = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    $initial                                      = $laser->doSnap($owner)[0];
    for ($i = 0; $i < intdiv($delayMs, 20); $i++) {
        $clock->next();
        $laser->doTick();
        expect($laser->doSnap($owner)[0]->getInts())->toBe($initial->getInts());
        expect($laser->isToDestroy())->toBeFalse();
    }

    $clock->next();
    $laser->doTick();

    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect($snap->startTick)->toBe($clock->get());
    expect([$snap->fromX, $snap->x])->toBe([255, -354]);
    expect($laser->isToDestroy())->toBeFalse();
    for ($i = 0; $i < intdiv($delayMs, 20); $i++) {
        $clock->next();
        $laser->doTick();
        expect($laser->isToDestroy())->toBeFalse();
    }
    $clock->next();
    $laser->doTick();
    expect($laser->isToDestroy())->toBeTrue();
})->with([0, 100, 150, 160]);

test('a laser at its bounce limit remains visible until its segment expires', function () {
    [$world, $clock, $owner]                    = createLaserBounceWorld();
    $world->getTuneController()->laserBounceNum = 0;
    $laser                                      = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    for ($i = 0; $i < 7; $i++) {
        $clock->next();
        $laser->doTick();
        expect($laser->isToDestroy())->toBeFalse();
    }

    $clock->next();
    $laser->doTick();

    expect($laser->isToDestroy())->toBeTrue();
    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect([$snap->fromX, $snap->x])->toBe([64, 255]);
});

test('a paused laser preserves the remaining bounce delay', function () {
    [$world, $clock, $owner] = createLaserBounceWorld();
    $laser                   = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    for ($i = 0; $i < 20; $i++) {
        $clock->next();
        $laser->tickPaused();
    }
    for ($i = 0; $i < 7; $i++) {
        $clock->next();
        $laser->doTick();
    }
    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect([$snap->fromX, $snap->x, $snap->startTick])->toBe([64, 255, 120]);

    $clock->next();
    $laser->doTick();
    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect([$snap->fromX, $snap->x, $snap->startTick])->toBe([255, -354, 128]);
});

test('a reflected laser skips its owner and uses the projected endpoint for another character', function (bool $withVictim) {
    [$world, $clock, $owner] = createLaserBounceWorld();
    $character               = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $owner);
    $world->addEntity($character);
    $victim = null;
    if ($withVictim) {
        $victimTee = new PlayerTee;
        $world->addTee($victimTee);
        $victim = new PvpCharacterEntity($world, new Vector2(32, 80));
        $victim->spawn(new Vector2(32, 80), $victimTee);
        $world->addEntity($victim);
    }
    $laser = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    for ($i = 0; $i < 8; $i++) {
        $clock->next();
        $laser->doTick();
    }

    expect($character->health)->toBe(10);
    expect($victim?->health)->toBe($withVictim ? 5 : null);
    $snap = $laser->doSnap($owner)[0];
    assert($snap instanceof ObjLaserItem);
    expect([$snap->fromX, $snap->fromY, $snap->x, $snap->y])->toBe([255, 64, $withVictim ? 32 : -354, 64]);
})->with([false, true]);

test('a reflected laser damages a character only when the reflected segment is evaluated', function () {
    [$world, $clock, $owner] = createLaserBounceWorld();
    $victimTee               = new PlayerTee;
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2(32, 64));
    $victim->spawn(new Vector2(32, 64), $victimTee);
    $world->addEntity($victim);
    $laser = new PvpLaserEntity($world, new Vector2(64, 64), new Vector2(1, 0), 800, $owner->teeIndex);
    expect($victim->health)->toBe(10);
    for ($i = 0; $i < 7; $i++) {
        $clock->next();
        $laser->doTick();
        expect($victim->health)->toBe(10);
    }

    $clock->next();
    $laser->doTick();

    expect($victim->health)->toBe(5);
    expect($laser->isToDestroy())->toBeFalse();
    expect(soundsSentToTee($world, $owner->teeIndex))->toBe([GameConstants::SOUND_HIT]);
});
