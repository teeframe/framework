<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Entities\PickupEntity;
use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\Entities\PvpProjectileEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Map\MapLayers\GameLayer;
use TeeFrame\Network\SnapItems\ObjEventSoundWorldItem;
use TeeFrame\Network\SnapItems\ObjLaserItem;
use TeeFrame\Network\SnapItems\ObjProjectileItem;

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

function setWeaponTestAim(PvpCharacterEntity $character, int $angle): Vector2
{
    $targetX = (int) round(cos($angle / 256.0) * 10000);
    $targetY = (int) round(sin($angle / 256.0) * 10000);
    $character->onDirectInput(input(['targetX' => $targetX, 'targetY' => $targetY]));

    return (new Vector2($targetX, $targetY))->normalize();
}

test('weapon firing preserves aim muzzle positions sounds and cooldowns', function (
    string $method,
    int $entityCount,
    int $soundId,
    int $cooldown,
    int $angle,
) {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $direction                 = setWeaponTestAim($character, $angle);
    $start                     = clone $character->getPosition();

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
            $speedFactor = $method === 'shootShotgun' ? [0.8, 0.9, 1.0, 0.9, 0.8][$index] : 1.0;
            expect($entity->direction->x)->toEqualWithDelta(cos($bulletAngle) * $speedFactor, 0.000000001);
            expect($entity->direction->y)->toEqualWithDelta(sin($bulletAngle) * $speedFactor, 0.000000001);
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

test('projectile setup preserves weapon tuning and truncates lifetimes to whole ticks', function (string $method, int $lifetime, int $expectedTicks) {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $tune                      = $world->getTuneController();
    $tune->gunSpeed            = 167500;
    $tune->gunCurvature        = 175;
    $tune->shotgunSpeed        = 289900;
    $tune->shotgunCurvature    = 225;
    $tune->grenadeSpeed        = 86500;
    $tune->grenadeCurvature    = 975;
    $tune->gunLifetime         = $lifetime;
    $tune->shotgunLifetime     = $lifetime;
    $tune->grenadeLifetime     = $lifetime;
    (new ReflectionMethod($character, $method))->invoke($character);
    $projectiles = array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity);

    [$speed, $curvature] = match ($method) {
        'shootGun'     => [1675.0, 1.75],
        'shootShotgun' => [2899.0, 2.25],
        'shootGrenade' => [865.0, 9.75],
        default        => throw new LogicException('Unexpected projectile weapon: '.$method),
    };

    expect($projectiles)->not->toBeEmpty();
    foreach ($projectiles as $projectile) {
        expect((new ReflectionProperty($projectile, 'lifeSpan'))->getValue($projectile))->toBe($expectedTicks);
        expect((new ReflectionProperty($projectile, 'speed'))->getValue($projectile))->toBe($speed);
        expect((new ReflectionProperty($projectile, 'curvature'))->getValue($projectile))->toBe($curvature);
        expect($projectile->owner)->toBe($tee->teeIndex);
    }
})->with(['shootGun', 'shootShotgun', 'shootGrenade'])->with([
    'fractional tick'    => [201, 100],
    'less than one tick' => [1, 0],
    'zero lifetime'      => [0, 0],
]);

test('shotgun pellets keep independent position objects', function () {
    [$world, $character] = createWeaponHelpersWorld();
    (new ReflectionMethod($character, 'shootShotgun'))->invoke($character);
    $pellets = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($pellets)->toHaveCount(5);
    $position = clone $pellets[1]->getPosition();
    $pellets[0]->getPosition()->x += 10;

    expect($pellets[1]->getPosition())->toEqual($position);
});

test('shotgun pellet snapshots and physics use the same tuned trajectories', function (int $speedDiff, int $angle) {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $tune                      = $world->getTuneController();
    $tune->shotgunSpeeddiff    = $speedDiff;
    $tune->shotgunSpeed        = 300000;
    $tune->shotgunCurvature    = 200;
    $direction                 = setWeaponTestAim($character, $angle);
    (new ReflectionMethod($character, 'shootShotgun'))->invoke($character);
    $pellets = array_values(array_filter($world->getEntities(), fn ($entity) => $entity instanceof PvpProjectileEntity));
    expect($pellets)->toHaveCount(5);
    $spread       = [-0.185, -0.070, 0, 0.070, 0.185];
    $speedFactors = [$speedDiff / 100, (1 + $speedDiff / 100) / 2, 1.0, (1 + $speedDiff / 100) / 2, $speedDiff / 100];
    $time         = 0.1;
    $distance     = $tune->shotgunSpeed / 100 * $time;

    foreach ($pellets as $index => $pellet) {
        $pelletAngle = atan2($direction->y, $direction->x) + $spread[$index];
        $dx          = cos($pelletAngle) * $speedFactors[$index];
        $dy          = sin($pelletAngle) * $speedFactors[$index];
        $start       = $pellet->getPosition();
        $position    = (new ReflectionMethod($pellet, 'calculatePositionAtTime'))->invoke($pellet, $time);
        assert($position instanceof Vector2);
        $drop = ($tune->shotgunCurvature / 100 / 10000) * $distance ** 2;
        expect($position->x)->toEqualWithDelta($start->x + $dx * $distance, 0.000000001);
        expect($position->y)->toEqualWithDelta($start->y + $dy * $distance + $drop, 0.000000001);

        $snap = $pellet->doSnap($tee)[0];
        assert($snap instanceof ObjProjectileItem);
        expect([$snap->velX, $snap->velY])->toBe([(int) ($dx * 100), (int) ($dy * 100)]);
        $quantizationError = 1 + $distance / 100;
        expect($position->x)->toEqualWithDelta($snap->x + $snap->velX / 100 * $distance, $quantizationError);
        expect($position->y)->toEqualWithDelta($snap->y + $snap->velY / 100 * $distance + $drop, $quantizationError);
    }
})->with([80, 50, 100])->with([0, 402, -603]);

test('ninja hits each target once per dash and emits the hit sound at the target', function () {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $victimTee                 = new PlayerTee;
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2(228.25, 96.75));
    $victim->spawn(new Vector2(228.25, 96.75), $victimTee);
    $world->addEntity($victim);
    $character->giveNinja();
    setWeaponTestAim($character, 0);
    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    $world->clearEvents();
    $handle = new ReflectionMethod($character, 'handleNinja');

    $handle->invoke($character);
    expect($victim->health)->toBe(1);
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
    expect($character->ninjaHitObjects)->toHaveCount(1);
    expect(array_filter(
        $world->doSnap($tee),
        fn ($item) => $item instanceof ObjEventSoundWorldItem && $item->soundId === GameConstants::SOUND_NINJA_HIT,
    ))->toBe([]);
});

test('collecting another ninja mid dash preserves movement and cannot hit the same target twice', function () {
    [$world, $character, $tee] = createWeaponHelpersWorld();
    $victimTee                 = new PlayerTee;
    $world->addTee($victimTee);
    $victim = new PvpCharacterEntity($world, new Vector2(228.25, 96.75));
    $victim->spawn(new Vector2(228.25, 96.75), $victimTee);
    $world->addEntity($victim);
    $character->giveNinja();
    setWeaponTestAim($character, 0);
    $character->vel         = new Vector2(3, 4);
    $character->reloadTimer = (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    $handle                 = new ReflectionMethod($character, 'handleNinja');
    $handle->invoke($character);
    expect($victim->health)->toBe(1);
    expect($character->ninjaHitObjects)->toBe([$victim]);
    $pickup = new PickupEntity($world, clone $character->getPosition(), GameConstants::POWERUP_NINJA);
    $world->addEntity($pickup);
    setWeaponTestAim($character, -402);

    $pickup->doTick();

    expect($pickup->doSnap($tee))->toBeEmpty();
    expect($character->ninjaCurrentMoveTime)->toBe(9);
    expect($character->ninjaActivationDir)->toEqual(new Vector2(1, 0));
    expect($character->ninjaOldVelAmount)->toBe(5.0);
    expect($character->ninjaHitObjects)->toBe([$victim]);
    expect($character->reloadTimer)->toBe(40);
    expect($character->lastWeapon)->toBe(GameConstants::WEAPON_GUN);
    $handle->invoke($character);
    expect($character->ninjaCurrentMoveTime)->toBe(8);
    expect($character->getPosition()->x)->toEqualWithDelta(228.25, 0.000001);
    expect($character->getPosition()->y)->toBe(96.75);
    expect($victim->health)->toBe(1);
    expect($character->ninjaHitObjects)->toBe([$victim]);

    for ($i = 0; $i < 8; $i++) {
        $handle->invoke($character);
    }
    expect($character->ninjaCurrentMoveTime)->toBe(0);
    expect($character->vel)->toEqual(new Vector2(5, 0));
    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    expect($character->ninjaCurrentMoveTime)->toBe(10);
    expect($character->ninjaHitObjects)->toBeEmpty();
});

test('ninja hits every target only once per dash and resets tracking for the next dash', function (int $targetCount) {
    [$world, $character] = createWeaponHelpersWorld();
    $victims             = [];
    for ($i = 0; $i < $targetCount; $i++) {
        $victim = new class($world, clone $character->getPosition()) extends PvpCharacterEntity
        {
            public int $hits = 0;

            public function takeDamage(Vector2 $force, int $damage, int $attackerTeeIndex, int $weapon): void
            {
                $this->hits++;
            }
        };
        $victim->spawn(clone $character->getPosition());
        $world->addEntity($victim);
        $victims[] = $victim;
    }
    $character->giveNinja();
    $hit = new ReflectionMethod($character, 'hitNinjaTargets');
    $hit->invoke($character);
    expect($character->ninjaHitObjects)->toBe($victims);
    $hit->invoke($character);
    foreach ($victims as $victim) {
        expect($victim->hits)->toBe(1);
    }
    expect($character->ninjaHitObjects)->toHaveCount($targetCount);

    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    expect($character->ninjaHitObjects)->toBeEmpty();
    $hit->invoke($character);
    expect($character->ninjaHitObjects)->toBe($victims);
    foreach ($victims as $victim) {
        expect($victim->hits)->toBe(2);
    }
})->with([11, 63]);

test('ninja restores the original speed along the dash direction when movement ends', function () {
    [, $character] = createWeaponHelpersWorld();
    $character->giveNinja();
    $direction      = setWeaponTestAim($character, 402);
    $character->vel = new Vector2(3, 4);
    (new ReflectionMethod($character, 'shootNinja'))->invoke($character);
    $handle = new ReflectionMethod($character, 'handleNinja');
    for ($i = 0; $i < 10; $i++) {
        $handle->invoke($character);
    }

    expect($character->ninjaCurrentMoveTime)->toBe(0);
    expect($character->vel->x)->toEqualWithDelta($direction->x * 5, 0.000000001);
    expect($character->vel->y)->toEqualWithDelta($direction->y * 5, 0.000000001);
});
