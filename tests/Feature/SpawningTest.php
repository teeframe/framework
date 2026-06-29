<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\NetworkMessages;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

/**
 * Build a world whose doTick() actually runs (unlike createWorld() which stubs it).
 */
function createTickingWorld(Map $map, TickHandler $tickHandler): AbstractWorld
{
    return new TestWorld($tickHandler, $map);
}

function setTick(TickHandler $tickHandler, int $tick): void
{
    $ref = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setAccessible(true);
    $prop->setValue($tickHandler, $tick);
}

test('tee respawns after respawnTick elapses via doTick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createTickingWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'Respawner';
    $world->addTee($tee);

    // Tee starts with spawning=true and respawnTick=0 (from addTee), so the first
    // doTick() should immediately spawn a character.
    expect($tee->spawning)->toBeTrue();
    expect($tee->character)->toBeNull();

    $world->doTick();

    expect($tee->spawning)->toBeFalse();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);
    expect($character->alive)->toBeTrue();

    // A spawn event should have been emitted. The tee's viewPosition is still
    // (0,0) (the character hasn't ticked yet to update it), so move it close
    // enough for the event-snap distance filter to include the spawn event.
    $tee->viewPosition = $character->getPosition();
    $events = $world->doSnap($tee);
    $spawnEvents = array_filter(
        $events,
        fn ($s) => $s->getItemId() === NetworkMessages::NETEVENTTYPE_SPAWN,
    );
    expect($spawnEvents)->not->toBeEmpty();
});

test('dead tee respawns after respawnTick elapses', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createTickingWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'AutoRespawner';
    $world->addTee($tee);

    // First tick: spawn the character
    $world->doTick();
    $originalCharacter = $tee->character;
    assert($originalCharacter instanceof PvpCharacterEntity);

    // Kill the character (self-kill → respawnTick = currentTick + 150 = 3s)
    $originalCharacter->die(-1, GameConstants::WEAPON_SELF);

    expect($tee->character)->toBeNull();
    expect($tee->spawning)->toBeTrue();
    expect($tee->dieTick)->toBe(100);
    expect($tee->respawnTick)->toBe(100 + 150);

    // Tick forward but before respawnTick — should NOT respawn yet
    setTick($tickHandler, 249); // 149 ticks later, still < 250
    $world->doTick();
    expect($tee->character)->toBeNull();

    // Reach respawnTick — should respawn now
    setTick($tickHandler, 250);
    $world->doTick();

    $respawnedCharacter = $tee->character;
    assert($respawnedCharacter instanceof PvpCharacterEntity);
    expect($respawnedCharacter)->not->toBe($originalCharacter);
    expect($respawnedCharacter->alive)->toBeTrue();
});

test('dead tee respawns on fire press after respawnTick', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createTickingWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'FireRespawner';
    $world->addTee($tee);

    // Spawn
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);

    // Kill with normal death (0.5s respawn = 25 ticks)
    $character->die(0);
    expect($tee->respawnTick)->toBe(100 + 25);

    // Advance 1 tick and press fire — respawnTick hasn't elapsed (101 < 125),
    // but fire-to-respawn should set spawning=true. The actual respawn still
    // waits for respawnTick (CPlayer::Tick checks m_RespawnTick <= Tick).
    setTick($tickHandler, 101);
    $tee->inputs[101] = new \TeeFrame\Game\PlayerInput(
        direction: 0, targetX: 0, targetY: -1, jump: false,
        fire: 1, hook: false, playerFlags: 0,
        wantedWeapon: 0, nextWeapon: 0, prevWeapon: 0,
    );
    $world->doTick();

    // spawning should be true (fire pressed) but character still null (respawnTick not reached)
    expect($tee->spawning)->toBeTrue();
    expect($tee->character)->toBeNull();

    // Now advance past respawnTick — should respawn
    setTick($tickHandler, 125);
    $world->doTick();
    $respawnedCharacter = $tee->character;
    assert($respawnedCharacter instanceof PvpCharacterEntity);
    expect($respawnedCharacter->alive)->toBeTrue();
});

test('dead tee auto-respawns at respawnTick without fire press', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createTickingWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'IdleRespawner';
    $world->addTee($tee);

    // Spawn
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);

    // Normal kill (0.5s respawn)
    $character->die(0);

    // The 0.5s respawn should fire at tick 125
    setTick($tickHandler, 125);
    $world->doTick();
    $respawnedCharacter = $tee->character;
    assert($respawnedCharacter instanceof PvpCharacterEntity);
    expect($respawnedCharacter->alive)->toBeTrue();
});

test('tryRespawnTee clears spawning flag on success', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createTickingWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'ClearFlag';
    $world->addTee($tee);

    expect($tee->spawning)->toBeTrue();

    $world->doTick();

    expect($tee->spawning)->toBeFalse();
    expect($tee->character)->not->toBeNull();
});

test('world with custom game controller still collects spawn points', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);

    // A world that overrides bootGameController() to install a custom controller,
    // exactly like the skeleton's Dm1World does. collectSpawnPoints() must still
    // run against the installed controller.
    $world = new class($tickHandler, $map) extends TestWorld
    {
        public \TeeFrame\Game\AbstractGameController $customController;

        protected function bootGameController(): void
        {
            $this->customController = new \TestGameController($this->tickHandler);
            $this->gameController = $this->customController;
        }
    };

    $tee = new PlayerTee;
    $tee->name = 'CustomController';
    $world->addTee($tee);

    $world->doTick();

    // The tee must have spawned — if collectSpawnPoints() didn't run against
    // the custom controller, canSpawn() would return null and the tee would
    // stay dead forever.
    expect($tee->spawning)->toBeFalse();
    $character = $tee->character;
    assert($character instanceof \TeeFrame\Game\Entities\Character\AbstractCharacterEntity);
    expect($character->alive)->toBeTrue();
});
