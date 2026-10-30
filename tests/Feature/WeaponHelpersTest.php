<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjLaserItem;

/** @return array{AbstractWorld, PvpCharacterEntity, PlayerTee} */
function createWeaponHelpersWorld(): array
{
    $map = new class extends Map
    {
        public function __construct()
        {
            $this->gameLayer = new GameLayer(64, 16, str_repeat(pack('C4', GameLayer::TILE_AIR, 0, 0, 0), 64 * 16));
            $this->collision = $this->createCollision($this->gameLayer);
        }
    };
    $world     = createWorld($map);
    $tee       = new PlayerTee;
    $position  = new Vector2(128.25, 96.75);
    $character = new PvpCharacterEntity($world, $position);
    $world->addTee($tee);
    $character->spawn($position, $tee);
    $world->addEntity($character);

    return [$world, $character, $tee];
}

test('weapon firing preserves aim muzzle positions sounds and cooldowns', function (
    string $method,
    int $entityCount,
    int $soundId,
    int $cooldown,
    int $angle,
) {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $character->angle          = $angle;
    $start                     = clone $character->getPosition();
    $direction                 = new Vector2(cos($angle / 256.0), sin($angle / 256.0));

    expect((new ReflectionMethod($character, $method))->invoke($character))->toBe($cooldown);
    expect($character->getPosition())->toEqual($start);

    $entities = array_values(array_filter($world->getEntities(), fn ($entity) => $entity !== $character));
    expect($entities)->toHaveCount($entityCount);
    foreach ($entities as $index => $entity) {
        if ($entity instanceof PvpProjectileEntity) {
            expect($entity->getPosition()->x)->toEqualWithDelta($start->x + $direction->x * 21, 0.000000001);
            expect($entity->getPosition()->y)->toEqualWithDelta($start->y + $direction->y * 21, 0.000000001);
            expect($entity->getPosition())->not->toBe($character->getPosition());
            $spread      = $method === 'shootShotgun' ? [-0.185, -0.070, 0, 0.070, 0.185][$index] : 0;
            $bulletAngle = atan2($direction->y, $direction->x) + $spread;
            expect($entity->direction->x)->toEqualWithDelta(cos($bulletAngle), 0.000000001);
            expect($entity->direction->y)->toEqualWithDelta(sin($bulletAngle), 0.000000001);
        } elseif ($entity instanceof PvpLaserEntity) {
            $snap = $entity->doSnap($tee)[0];
            assert($snap instanceof ObjLaserItem);
            expect($snap->fromX)->toBe((int) round($start->x));
            expect($snap->fromY)->toBe((int) round($start->y));
            expect($snap->x)->toBe((int) round($start->x + $direction->x * 800));
            expect($snap->y)->toBe((int) round($start->y + $direction->y * 800));
        } else {
            throw new LogicException('Unexpected weapon entity');
        }
    }
    if ($method === 'shootNinja') {
        expect($character->ninjaActivationDir->x)->toEqualWithDelta($direction->x, 0.000000001);
        expect($character->ninjaActivationDir->y)->toEqualWithDelta($direction->y, 0.000000001);
    }

    $sounds = array_values(array_filter($world->doSnap($tee), fn ($item) => $item instanceof ObjEventSoundWorldItem));
    expect($sounds)->toHaveCount(1);
    expect($sounds[0]->getInts())->toBe([(int) round($start->x), (int) round($start->y), $soundId]);
})->with([
    'hammer'  => ['shootHammer', 0, GameConstants::SOUND_HAMMER_FIRE, 6],
    'gun'     => ['shootGun', 1, GameConstants::SOUND_GUN_FIRE, 6],
    'shotgun' => ['shootShotgun', 5, GameConstants::SOUND_SHOTGUN_FIRE, 25],
    'grenade' => ['shootGrenade', 1, GameConstants::SOUND_GRENADE_FIRE, 25],
    'rifle'   => ['shootRifle', 1, GameConstants::SOUND_RIFLE_FIRE, 40],
    'ninja'   => ['shootNinja', 0, GameConstants::SOUND_NINJA_FIRE, 40],
])->with([0, 402, -603]);

test('shotgun pellets keep independent position objects', function () {
    [$world, $character] = createWeaponHelpersWorld();
    (new ReflectionMethod($character, 'shootShotgun'))->invoke($character);
    $pellets = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($pellets)->toHaveCount(5);
    $position = clone $pellets[1]->getPosition();
    $pellets[0]->getPosition()->x += 10;

    expect($pellets[1]->getPosition())->toEqual($position);
});

test('ninja hits each target once per dash and emits the hit sound at the target', function () {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $victimTee                 = new PlayerTee;
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2(228.25, 96.75));
    $victim->spawn(new Vector2(228.25, 96.75), $victimTee);
    $world->addEntity($victim);
    $character->giveNinja();
    $character->angle = 0;
    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    $world->clearEvents();
    $handle = new ReflectionMethod($character, 'handleNinja');

    $handle->invoke($character);
    expect($victim->health)->toBe(1);
    expect($character->ninjaNumObjectsHit)->toBe(1);
    expect($character->ninjaHitObjects)->toBe([$victim]);

    $sounds = array_values(array_filter(
        $world->doSnap($tee),
        fn ($item) => $item instanceof ObjEventSoundWorldItem && $item->soundId === GameConstants::SOUND_NINJA_HIT,
    ));
    expect($sounds)->toHaveCount(1);
    expect($sounds[0]->getInts())->toBe([228, 97, GameConstants::SOUND_NINJA_HIT]);
    $world->clearEvents();
    $handle->invoke($character);

    expect($victim->health)->toBe(1);
    expect($character->ninjaNumObjectsHit)->toBe(1);
    expect(array_filter(
        $world->doSnap($tee),
        fn ($item) => $item instanceof ObjEventSoundWorldItem && $item->soundId === GameConstants::SOUND_NINJA_HIT,
    ))->toBe([]);
});

test('ninja restores the original speed along the dash direction when movement ends', function () {
    [, $character] = createWeaponHelpersWorld();
    $character->giveNinja();
    $character->angle = 402;
    $character->vel   = new Vector2(3, 4);
    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    $handle = new ReflectionMethod($character, 'handleNinja');
    for ($i = 0; $i < 10; $i++) {
        $handle->invoke($character);
    }

    expect($character->ninjaCurrentMoveTime)->toBe(0);
    expect($character->vel->x)->toEqualWithDelta(cos(402 / 256.0) * 5, 0.000000001);
    expect($character->vel->y)->toEqualWithDelta(sin(402 / 256.0) * 5, 0.000000001);
});
