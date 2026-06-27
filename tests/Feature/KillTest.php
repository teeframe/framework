<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClKillChunk;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

$mapPath   = __DIR__.'/../dm1.map';
$mapExists = file_exists($mapPath);

function createTickingWorldForKill(Map $map, TickHandler $tickHandler): AbstractWorld
{
    return new class('test', $tickHandler, $map, $GLOBALS['mockGameServer']) extends AbstractWorld
    {
        public function getMotd(AbstractTee $requestingTee): string
        {
            return '';
        }

        protected function bootGameController(): void
        {
            $this->gameController = new TestGameController($this->tickHandler);
        }
    };
}

function setTickForKill(TickHandler $tickHandler, int $tick): void
{
    $ref  = new ReflectionClass($tickHandler);
    $prop = $ref->getProperty('currentTick');
    $prop->setAccessible(true);
    $prop->setValue($tickHandler, $tick);
}

test('ClKillChunk encodes and decodes correctly', function () {
    $chunk = new ClKillChunk;

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClKillChunk::make(new RawPayload($payload));

    expect($decoded)->toBeInstanceOf(ClKillChunk::class);
});

test('onMessage handles ClKillChunk by killing the character', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createTickingWorldForKill($map, $tickHandler);

    $tee       = new PlayerTee;
    $tee->name = 'Killer';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);
    expect($character->alive)->toBeTrue();

    // Send ClKill — should kill the character
    $world->onMessage($tee, new ClKillChunk);

    expect($tee->character)->toBeNull();
    expect($character->alive)->toBeFalse();
    expect($tee->lastKillTick)->toBe(100);
    expect($tee->spawning)->toBeTrue();
});

test('ClKill is ignored during the 3 second cooldown', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createTickingWorldForKill($map, $tickHandler);

    $tee       = new PlayerTee;
    $tee->name = 'CooldownKiller';
    $world->addTee($tee);

    // Spawn
    $world->doTick();
    $firstCharacter = $tee->character;
    assert($firstCharacter instanceof PvpCharacterEntity);

    // First kill succeeds
    $world->onMessage($tee, new ClKillChunk);
    expect($tee->lastKillTick)->toBe(100);
    expect($tee->character)->toBeNull();

    // Respawn the character so we can verify a second kill attempt.
    // respawnTick = 100 + 150 = 250 (self-kill penalty).
    setTickForKill($tickHandler, 250);
    $world->doTick();
    $secondCharacter = $tee->character;
    assert($secondCharacter instanceof PvpCharacterEntity);

    // Cooldown window: lastKillTick + 150 > currentTick → 100 + 150 > 249 → blocked.
    setTickForKill($tickHandler, 249);
    $world->onMessage($tee, new ClKillChunk);
    expect($tee->character)->not->toBeNull();
    expect($tee->lastKillTick)->toBe(100); // unchanged

    // After cooldown elapses (currentTick >= 250), kill works again
    setTickForKill($tickHandler, 250);
    $world->onMessage($tee, new ClKillChunk);
    expect($tee->character)->toBeNull();
    expect($tee->lastKillTick)->toBe(250);
});

test('ClKill with no character does nothing', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createTickingWorldForKill($map, $tickHandler);

    $tee       = new PlayerTee;
    $tee->name = 'NoCharacter';
    $world->addTee($tee);

    // Don't tick — no character spawned yet
    expect($tee->character)->toBeNull();

    $world->onMessage($tee, new ClKillChunk);

    expect($tee->character)->toBeNull();
    expect($tee->lastKillTick)->toBe(100);
});

test('ClKill emits a death event at the character position', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map         = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world       = createTickingWorldForKill($map, $tickHandler);

    $tee       = new PlayerTee;
    $tee->name = 'DeathEvent';
    $world->addTee($tee);

    // Spawn the character
    $world->doTick();
    $character = $tee->character;
    assert($character instanceof PvpCharacterEntity);

    // Self-kill — should emit a NETEVENTTYPE_DEATH event at the character's position
    $world->onMessage($tee, new ClKillChunk);

    $tee->viewPosition = $character->getPosition();
    $events            = $world->doSnap($tee);
    $deathEvents       = array_filter(
        $events,
        fn ($s) => $s->getItemId() === NetworkMessages::NETEVENTTYPE_DEATH,
    );
    expect($deathEvents)->not->toBeEmpty();
});
