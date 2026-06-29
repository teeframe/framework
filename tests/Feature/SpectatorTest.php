<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSetSpectatorModeChunk;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\RawPayload;

$mapPath = __DIR__ . '/../dm1.map';
$mapExists = file_exists($mapPath);

function createSpectatorWorld(Map $map, TickHandler $tickHandler): AbstractWorld
{
    return new class($tickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };
}

test('ClSetTeamChunk encodes and decodes correctly', function () {
    $chunk = new ClSetTeamChunk(team: GameConstants::TEAM_SPECTATORS);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClSetTeamChunk::make(new RawPayload($payload));

    expect($decoded->team)->toBe(GameConstants::TEAM_SPECTATORS);
});

test('ClSetSpectatorModeChunk encodes and decodes correctly', function () {
    $chunk = new ClSetSpectatorModeChunk(spectatorId: 3);

    $encoded = $chunk->encode();
    $payload = array_slice($encoded, 4);

    $decoded = ClSetSpectatorModeChunk::make(new RawPayload($payload));

    expect($decoded->spectatorId)->toBe(3);
});

test('setTeam moves player to spectators and kills character', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'TestPlayer';
    $world->addTee($tee);

    // Spawn a character
    $spawnPos = new Vector2(100, 100);
    $character = new PvpCharacterEntity($world, clone $spawnPos);
    $character->spawn(clone $spawnPos, $tee);
    $world->addEntity($character);
    expect($tee->character)->not->toBeNull();
    expect($tee->team)->toBe(GameConstants::TEAM_RED);

    // Move to spectators
    $tee->setTeam(GameConstants::TEAM_SPECTATORS);

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee->character)->toBeNull();
    expect($tee->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($tee->respawnTick)->toBeGreaterThan(100);
});

test('setTeam broadcasts joined the spectators chat message', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Joiner';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Witness';
    $world->addTee($tee2);

    $tee1->setTeam(GameConstants::TEAM_SPECTATORS);

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof \TeeFrame\Network\Chunks\Game\SvChatChunk);
    $joinChats = array_filter($chats, fn ($c) => str_contains($c->text, "'Joiner' joined the spectators"));
    expect($joinChats)->not->toBeEmpty();
});

test('setTeam broadcasts joined the game chat message', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'Returner';
    $world->addTee($tee);

    // First move to spectators
    $tee->setTeam(GameConstants::TEAM_SPECTATORS);
    resetMockServer();

    // Now move back to the game
    $tee->setTeam(GameConstants::TEAM_RED);

    $chats = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof \TeeFrame\Network\Chunks\Game\SvChatChunk);
    $joinChats = array_filter($chats, fn ($c) => str_contains($c->text, "'Returner' joined the game"));
    expect($joinChats)->not->toBeEmpty();
});

test('ClSetTeam to spectators works via onMessage', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'Joiner';
    $world->addTee($tee);

    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));

    expect($tee->team)->toBe(GameConstants::TEAM_SPECTATORS);
    expect($tee->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
});

test('team change cooldown broadcasts time to wait', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'CooldownTest';
    $world->addTee($tee);

    // Set a team change cooldown in the future
    $tee->teamChangeTick = 100 + 60; // 60 ticks = ~1.2s

    // Try to change team — should be blocked with broadcast
    $world->onMessage($tee, new ClSetTeamChunk(GameConstants::TEAM_SPECTATORS));

    expect($tee->team)->toBe(GameConstants::TEAM_RED); // unchanged

    $broadcasts = array_filter($GLOBALS['mockGameServer']->sentChunks, fn ($c) => $c instanceof SvBroadcastChunk);
    expect($broadcasts)->not->toBeEmpty();

    $broadcast = reset($broadcasts);
    assert($broadcast instanceof SvBroadcastChunk);
    expect($broadcast->text)->toContain('Time to wait before changing team');
});

test('spectators can change spectator mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Spectator';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Player';
    $world->addTee($tee2);

    // tee1 becomes a spectator
    $tee1->setTeam(GameConstants::TEAM_SPECTATORS);

    // tee1 spectates tee2
    $world->onMessage($tee1, new ClSetSpectatorModeChunk($tee2->teeIndex));

    expect($tee1->spectatorId)->toBe($tee2->teeIndex);
});

test('non-spectators cannot change spectator mode', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee1 = new PlayerTee;
    $tee1->name = 'Player1';
    $world->addTee($tee1);

    $tee2 = new PlayerTee;
    $tee2->name = 'Player2';
    $world->addTee($tee2);

    // tee1 is not a spectator — trying to set spectator mode should be ignored
    $world->onMessage($tee1, new ClSetSpectatorModeChunk($tee2->teeIndex));

    expect($tee1->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
});

test('cannot spectate yourself', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'SelfSpec';
    $world->addTee($tee);

    $tee->setTeam(GameConstants::TEAM_SPECTATORS);

    // Try to spectate yourself — should be ignored
    $world->onMessage($tee, new ClSetSpectatorModeChunk($tee->teeIndex));

    expect($tee->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
});

test('spectator info is included in snap for local spectator', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'SpecSnap';
    $world->addTee($tee);

    $tee->setTeam(GameConstants::TEAM_SPECTATORS);
    $tee->viewPosition = new Vector2(500, 300);

    $snaps = $world->doSnap($tee);

    $specInfos = array_filter($snaps, fn ($s) => $s instanceof \TeeFrame\Network\SnapItems\ObjSpectatorInfoItem);
    expect($specInfos)->toHaveCount(1);

    $specInfo = reset($specInfos);
    assert($specInfo instanceof \TeeFrame\Network\SnapItems\ObjSpectatorInfoItem);
    expect($specInfo->spectatorId)->toBe(GameConstants::SPEC_FREEVIEW);
    expect($specInfo->x)->toBe(500);
    expect($specInfo->y)->toBe(300);
});

test('spectator info is not sent to non-spectators', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $tee = new PlayerTee;
    $tee->name = 'NonSpec';
    $world->addTee($tee);

    // tee is TEAM_RED, not a spectator
    $snaps = $world->doSnap($tee);

    $specInfos = array_filter($snaps, fn ($s) => $s instanceof \TeeFrame\Network\SnapItems\ObjSpectatorInfoItem);
    expect($specInfos)->toBeEmpty();
});

test('spectator view position follows spectated player', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = new class($tickHandler, $map) extends TestWorld {};

    $player = new PlayerTee;
    $player->name = 'Player';
    $world->addTee($player);

    $spectator = new PlayerTee;
    $spectator->name = 'Spectator';
    $world->addTee($spectator);

    // Spectator becomes a spectator and follows the player
    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);
    $spectator->spectatorId = $player->teeIndex;

    // Player moves to a far position
    $player->viewPosition = new Vector2(5000, 3000);

    // Spectator's view position is still at origin
    expect($spectator->viewPosition->x)->toEqual(0);
    expect($spectator->viewPosition->y)->toEqual(0);

    // Tick — spectator's view position should follow the player
    $world->doTick();

    expect($spectator->viewPosition->x)->toEqual(5000);
    expect($spectator->viewPosition->y)->toEqual(3000);
});

test('spectator in free view does not follow any player', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = new class($tickHandler, $map) extends TestWorld {};

    $player = new PlayerTee;
    $player->name = 'Player';
    $world->addTee($player);

    $spectator = new PlayerTee;
    $spectator->name = 'FreeViewer';
    $world->addTee($spectator);

    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);
    // spectatorId stays SPEC_FREEVIEW

    $player->viewPosition = new Vector2(5000, 3000);
    $spectator->viewPosition = new Vector2(100, 200);

    $world->doTick();

    // Free view: view position should NOT change
    expect($spectator->viewPosition->x)->toEqual(100);
    expect($spectator->viewPosition->y)->toEqual(200);
});

test('spectator sees entities regardless of distance', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $spectator = new PlayerTee;
    $spectator->name = 'FarSpectator';
    $world->addTee($spectator);
    $spectator->setTeam(GameConstants::TEAM_SPECTATORS);
    // Spectator is at origin
    $spectator->viewPosition = new Vector2(0, 0);

    // Place a pickup very far away (well beyond the 1100 culling distance)
    $farPos = new Vector2(5000, 5000);
    $pickup = new \TeeFrame\Game\Entities\PickupEntity(
        world: $world,
        position: $farPos,
        type: GameConstants::POWERUP_HEALTH,
    );
    $world->addEntity($pickup);

    $snaps = $world->doSnap($spectator);

    // The spectator should see the pickup despite being far away
    $pickupSnaps = array_filter($snaps, fn ($s) => $s instanceof \TeeFrame\Network\SnapItems\ObjPickupItem);
    expect($pickupSnaps)->not->toBeEmpty();
});

test('non-spectator does not see entities beyond culling distance', function () use ($mapPath, $mapExists) {
    if (! $mapExists) {
        return;
    }

    resetMockServer();

    $map = new Map($mapPath);
    $tickHandler = new TickHandler(100);
    $world = createSpectatorWorld($map, $tickHandler);

    $player = new PlayerTee;
    $player->name = 'Player';
    $world->addTee($player);
    $player->viewPosition = new Vector2(0, 0);

    // Place a pickup very far away
    $farPos = new Vector2(5000, 5000);
    $pickup = new \TeeFrame\Game\Entities\PickupEntity(
        world: $world,
        position: $farPos,
        type: GameConstants::POWERUP_HEALTH,
    );
    $world->addEntity($pickup);

    $snaps = $world->doSnap($player);

    // The player should NOT see the pickup (beyond 1100 culling distance)
    $pickupSnaps = array_filter($snaps, fn ($s) => $s instanceof \TeeFrame\Network\SnapItems\ObjPickupItem);
    expect($pickupSnaps)->toBeEmpty();
});
