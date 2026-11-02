<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Server\Ban\BanList;
use TeeFrame\Server\Chat\MuteList;
use TeeFrame\Server\Console;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

/**
 * @param  Closure(): void  $action
 * @return list<string>
 */
function captureGameLogs(Closure $action, int $level = Console::LEVEL_TRACE): array
{
    $lines    = [];
    $listener = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    Console::listen($listener, $level);
    try {
        $action();
    } finally {
        Console::unlisten($listener);
    }

    return $lines;
}

test('world console helpers preserve severity and subsystem and add the world prefix once', function (string $method, string $label) {
    $server = new RconTestServer;
    $world  = $server->entryWorld;

    $lines = captureGameLogs(function () use ($world, $method): void {
        $world->$method('test-system', 'Test message');
    });

    expect($lines)->toBe(['['.$label.'] [test-system]: World=dm Test message']);
})->with([
    ['consoleError', 'ERROR'],
    ['consoleWarn', 'WARN'],
    ['consoleInfo', 'INFO'],
    ['consoleDebug', 'DEBUG'],
    ['consoleTrace', 'TRACE'],
]);

test('world console helpers use the current world identifier', function () {
    $server = new RconTestServer;
    $world  = $server->entryWorld;

    $lines = captureGameLogs(function () use ($world): void {
        $world->consoleInfo('game', 'First message');
        $world->identifier = 'renamed';
        $world->consoleInfo('game', 'Second message');
    });

    expect($lines)->toBe([
        '[INFO] [game]: World=dm First message',
        '[INFO] [game]: World=renamed Second message',
    ]);
});

test('accepted public chat is logged once with its world and sender', function () {
    $server = new RconTestServer;
    $sender = $server->addClient();
    $server->addClient(port: 8301);
    $server->addClient(port: 8302);
    $server->entryWorld->setTeeName($sender->playerTee(), 'Alice');

    $lines = captureGameLogs(fn () => $server->send($sender, new ClSayChunk(false, '  hello  ')));

    expect($lines)->toBe(['[INFO] [chat]: World=dm TeeIdx=0 Name="Alice": hello']);
});

test('team and spectator chat are logged once rather than once per recipient', function (int $team) {
    $server                      = new RconTestServer;
    $sender                      = $server->addClient();
    $teammate                    = $server->addClient(port: 8301);
    $outsider                    = $server->addClient(port: 8302);
    $sender->playerTee()->team   = $team;
    $teammate->playerTee()->team = $team;
    $outsider->playerTee()->team = $team === -1 ? 0 : -1;
    $server->entryWorld->setTeeName($sender->playerTee(), 'Alice');

    $lines = captureGameLogs(fn () => $server->send($sender, new ClSayChunk(true, 'team message')));

    expect($lines)->toBe(['[INFO] [teamchat]: World=dm Team='.$team.' TeeIdx=0 Name="Alice": team message']);
})->with([-1, 0, 1]);

test('blocked and empty messages do not produce chat logs', function () {
    $server = new RconTestServer;
    $sender = $server->addClient();
    $server->tick();
    $server->send($sender, new ClSayChunk(false, 'first message'));

    expect(captureGameLogs(fn () => $server->send($sender, new ClSayChunk(false, 'spam'))))->toBe([]);
    $server->getChatMutes()->mute($sender->destinationAddress, 60, 'test');
    expect(captureGameLogs(fn () => $server->send($sender, new ClSayChunk(false, 'muted'))))->toBe([]);
    expect(captureGameLogs(fn () => $server->send($sender, new ClSayChunk(false, '   '))))->toBe([]);
});

test('chat command logs contain canonical names but never whisper contents or arguments', function () {
    $server    = new RconTestServer;
    $sender    = $server->addClient();
    $recipient = $server->addClient(port: 8301);
    $server->entryWorld->setTeeName($recipient->playerTee(), 'Bob');

    $lines = captureGameLogs(function () use ($server, $sender) {
        $server->send($sender, new ClSayChunk(false, '/w Bob private-message'));
        $server->send($sender, new ClSayChunk(false, '/c private-reply'));
        $server->send($sender, new ClSayChunk(false, '/help'));
    });

    expect($lines)->toBe([
        '[DEBUG] [chat-command]: World=dm TeeIdx=0 Chat command=whisper',
        '[DEBUG] [chat-command]: World=dm TeeIdx=0 Chat command=converse',
        '[DEBUG] [chat-command]: World=dm TeeIdx=0 Chat command=help',
    ]);
});

test('world announcements and broadcasts are logged once and private replies are not logged', function () {
    $server = new RconTestServer;
    $player = $server->addClient();
    $server->addClient(port: 8301);
    $world = $server->entryWorld;

    $lines = captureGameLogs(function () use ($server, $world, $player) {
        $world->broadcast(new SvChatChunk(0, -1, 'Server announcement'));
        $world->broadcast(new SvBroadcastChunk('On-screen message'));
        $server->sendToTee($world, $player->playerTee()->teeIndex, new SvChatChunk(0, -1, 'Private reply'));
        $server->getRcon()->sendLine($player, 'Private RCON reply');
    });

    expect($lines)->toBe([
        '[INFO] [chat]: World=dm *** Server announcement',
        '[INFO] [broadcast]: World=dm On-screen message',
    ]);
});

test('player name logs use the accepted name and omit no-op or rejected changes', function () {
    $server = new RconTestServer;
    $player = $server->addClient();
    $other  = $server->addClient(port: 8301);
    $world  = $server->entryWorld;
    $world->setTeeName($player->playerTee(), 'Alice');
    $world->setTeeName($other->playerTee(), 'Bob');

    $lines = captureGameLogs(function () use ($world, $player) {
        $world->trySetTeeName($player->playerTee(), '  New name  ');
        $world->trySetTeeName($player->playerTee(), 'New name');
        $world->trySetTeeName($player->playerTee(), 'Bob');
    });

    expect($lines)->toBe(['[INFO] [game]: World=dm TeeIdx=0 Name changed from "Alice" to "New name"']);
});

test('pause and round logs describe transitions without repeating every tick', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock, isTeamMode: true);
    $world      = new TestWorldWithController($clock, new Map, $controller);

    $lines = captureGameLogs(function () use ($controller, $world) {
        $controller->startRound();
        $world->setPaused(true);
        $world->setPaused(true);
        $controller->doTick();
        $world->setPaused(false);
        $controller->endRound();
        $controller->endRound();
    });

    expect($lines)->toBe([
        '[INFO] [game]: World=test Round started Gametype=TDM',
        '[INFO] [game]: World=test Paused',
        '[INFO] [game]: World=test Resumed',
        '[INFO] [game]: World=test Paused',
        '[INFO] [game]: World=test Round ended Gametype=TDM ScoreRed=0 ScoreBlue=0',
    ]);
});

test('sudden death is logged only when it starts', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock, scoreLimit: 1);
    $world      = new TestWorldWithController($clock, new Map, $controller);
    foreach (range(1, 2) as $_) {
        $player = new PlayerTee;
        $world->addTee($player);
        $player->score = 1;
    }

    $lines = captureGameLogs(function () use ($controller) {
        $controller->doWincheck();
        $controller->doWincheck();
    });

    expect($lines)->toBe(['[INFO] [game]: World=test Sudden death']);
});

test('world reloads are logged when queued and when applied rather than on each tick', function () {
    $server = new RconTestServer;
    $lines  = captureGameLogs(function () use ($server) {
        $server->entryWorld->reload(gameMode: 'tdm');
        $server->tick();
        $server->tick();
    });

    expect($lines)->toBe([
        '[DEBUG] [world]: World=dm Reload queued Map=dm1 Gametype=TDM',
        '[INFO] [world]: World=dm Reloaded Map=dm1 Gametype=TDM',
    ]);
});

test('failed reloads never log a successful reload', function () {
    $server  = new RconTestServer;
    $player  = $server->addClient();
    $command = $server->entryWorld->findCommand('change_map', AbstractCommand::TYPE_SERVER);
    assert($command !== null);

    $lines = captureGameLogs(function () use ($command, $server, $player): void {
        expect(fn () => $command->execute($server->entryWorld, $player->playerTee(), ['missing-map']))->toThrow(RuntimeException::class, 'Unable to change map to missing-map');
    });

    expect($lines[0])->toStartWith('[WARN] [world]: World=dm Map change failed:');
    expect(implode("\n", $lines))->not->toContain('Reload queued', 'Reloaded');
});

test('kills are logged at info level with actual killer victim and weapon', function (int $killerTeeIndex, int $weapon) {
    $server = new RconTestServer;
    $victim = $server->addClient();
    $server->addClient(port: 8301);
    $character = new PvpCharacterEntity($server->entryWorld, new Vector2(0, 0));
    $character->spawn(new Vector2(0, 0), $victim->playerTee());

    $lines = captureGameLogs(fn () => $character->die($killerTeeIndex, $weapon), Console::LEVEL_INFO);

    expect($lines)->toBe(['[INFO] [game]: World=dm Kill Killer='.$killerTeeIndex.' Victim=0 Weapon='.$weapon]);
})->with([
    'player kill' => [1, GameConstants::WEAPON_GRENADE],
    'self kill'   => [0, GameConstants::WEAPON_SELF],
    'world death' => [-1, GameConstants::WEAPON_WORLD],
]);

test('ban changes and expiration are logged once and include normalized addresses', function () {
    $bans  = new BanList;
    $lines = captureGameLogs(function () use ($bans) {
        $bans->ban('2001:0db8::1', 60, 'test reason');
        $bans->unban('2001:db8::1');
        $bans->unban('2001:db8::1');
        $bans->ban('127.0.0.1', 0, 'expired');
        $bans->cleanup();
        $bans->cleanup();
        $bans->ban('127.0.0.2', null, 'permanent');
        $bans->clear();
        $bans->clear();
    });

    expect($lines)->toBe([
        '[INFO] [ban]: Banned Address=2001:db8::1 Duration=60s Reason=test reason',
        '[INFO] [ban]: Unbanned Address=2001:db8::1',
        '[INFO] [ban]: Banned Address=127.0.0.1 Duration=0s Reason=expired',
        '[DEBUG] [ban]: Ban expired Address=127.0.0.1',
        '[INFO] [ban]: Banned Address=127.0.0.2 Duration=permanent Reason=permanent',
        '[INFO] [ban]: Cleared bans Count=1',
    ]);
});

test('mute changes and expiration are logged once and rejected changes are not logged', function () {
    $mutes = new MuteList;
    $lines = captureGameLogs(function () use ($mutes) {
        $mutes->mute('2001:0db8::1', 60, 'test reason');
        $mutes->unmute('2001:db8::1');
        $mutes->unmute('2001:db8::1');
        (new ReflectionProperty(MuteList::class, 'mutes'))->setValue($mutes, ['127.0.0.1' => ['expiry' => time() - 1, 'reason' => 'expired']]);
        $mutes->getMutes();
        $mutes->getMutes();
        expect(fn () => $mutes->mute('invalid-address', 60, 'invalid'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $mutes->mute('127.0.0.1', 0, 'invalid'))->toThrow(InvalidArgumentException::class);
    });

    expect($lines)->toBe([
        '[INFO] [mute]: Muted Address=2001:db8::1 Duration=60s Reason=test reason',
        '[INFO] [mute]: Unmuted Address=2001:db8::1',
        '[DEBUG] [mute]: Mute expired Address=127.0.0.1',
    ]);
});

test('vote start outcome and execution logs do not expose the configured command arguments', function () {
    $server = new RconTestServer;
    $player = $server->addClient()->playerTee();
    $world  = $server->entryWorld;
    $world->registerCommand(new RconTestCommand(params: 'r[value]'));
    $votes = $world->getVoteController();
    $votes->addVoteOption('Action', 'record private-argument');

    $lines = captureGameLogs(function () use ($votes, $world, $player) {
        $votes->callVote($world, $player, 'option', 'Action', 'test');
        $votes->tick($world);
        $votes->tick($world);
    });

    expect($lines)->toContain(
        '[INFO] [vote]: World=dm Vote started Description="Action" Reason="test"',
        '[DEBUG] [vote]: World=dm TeeIdx=0 Vote command=record',
        '[INFO] [vote]: World=dm Vote passed Description="Action" Yes=1 No=0 Total=1',
    );
    expect(implode("\n", $lines))->not->toContain('private-argument');
    expect(count(array_filter($lines, fn (string $line): bool => str_contains($line, '[vote]: World=dm Vote passed'))))->toBe(1);
});

test('rejected votes record the description and final counts', function () {
    $server = new RconTestServer;
    $caller = $server->addClient()->playerTee();
    $voter  = $server->addClient(port: 8301)->playerTee();
    $world  = $server->entryWorld;
    $votes  = $world->getVoteController();
    $votes->addVoteOption('Action', 'say changed');
    $votes->callVote($world, $caller, 'option', 'Action', 'test');
    $votes->vote($world, $voter, -1);

    $lines = captureGameLogs(fn () => $votes->tick($world));

    expect($lines)->toContain('[INFO] [vote]: World=dm Vote failed Description="Action" Yes=1 No=1 Total=2 Reason=rejected');
});

test('tuning changes are logged but queries and invalid tuning names are not', function () {
    $server = new RconTestServer;
    $player = $server->addClient();
    $server->send($player, new RconAuthChunk('', 'test-secret'));

    $lines = captureGameLogs(function () use ($server, $player) {
        $server->send($player, new RconCmdChunk('tune gravity 0.25; tune gravity; toggle_tune gravity 0.25 0.5; tune_reset gravity; tune invalid private-value'));
    });
    $tuningLogs = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, '[tuning]:')));

    expect($tuningLogs)->toBe([
        '[INFO] [tuning]: World=dm gravity=0.25',
        '[INFO] [tuning]: World=dm gravity=0.5',
        '[INFO] [tuning]: World=dm Tuning reset Parameter=gravity',
    ]);
    expect(implode("\n", $lines))->not->toContain('private-value', 'test-secret');
});

test('team transition logs are omitted when the team does not change', function () {
    $server = new RconTestServer;
    $player = $server->addClient()->playerTee();

    $lines = captureGameLogs(function () use ($player) {
        $player->setTeam(GameConstants::TEAM_SPECTATORS);
        $player->setTeam(GameConstants::TEAM_SPECTATORS);
    });

    $transitions = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'Team changed')));
    expect($transitions)->toBe(['[DEBUG] [game]: World=dm TeeIdx=0 Name="'.$player->name.'" Team changed from 0 to -1']);
});

test('RCON failure logs identify failed attempts without exposing credentials arguments or private errors', function () {
    $server = new RconTestServer;
    $client = $server->addClient();
    $server->entryWorld->registerCommand(new RconTestCommand(params: 'r[value]', handler: fn () => throw new RuntimeException('private-error-detail')));

    $lines = captureGameLogs(function () use ($server, $client) {
        $server->send($client, new RconAuthChunk('', 'wrong-secret'));
        $server->send($client, new RconAuthChunk('', 'test-secret'));
        $server->send($client, new RconCmdChunk('record private-argument'));
        $server->send($client, new RconCmdChunk("record private-argument\n"));
    });

    $output = implode("\n", $lines);
    expect($output)->toContain('RCON authentication failed Attempt=1/3', 'RCON command failed (RuntimeException)', 'RCON command rejected: invalid input');
    expect($output)->not->toContain('wrong-secret', 'test-secret', 'private-argument', 'private-error-detail');
});

test('banned and full connection attempts are logged at debug level', function () {
    $server = new RconTestServer;
    $server->getBanList()->ban('127.0.0.2', 60, 'test');
    $server->addClient();
    (new ReflectionProperty(AbstractWorld::class, 'slotsLimit'))->setValue($server->entryWorld, 1);

    $lines = captureGameLogs(function () use ($server) {
        $server->connect('127.0.0.2', 8301);
        $server->connect('127.0.0.3', 8302);
    });

    expect($lines)->toBe([
        '[DEBUG] [network]: Connection rejected Address=127.0.0.2 Port=8301 Reason=banned',
        '[DEBUG] [network]: Connection rejected Address=127.0.0.3 Port=8302 Reason=The server is full',
    ]);
});

test('world transfer logs identify the source connection and destination', function () {
    $server      = new RconTestServer;
    $client      = $server->addClient();
    $destination = $server->addWorld('destination');

    $lines = captureGameLogs(function () use ($server, $client, $destination): void {
        $server->changeWorld($client->playerTee(), $destination);
    });

    expect($lines)->toContain('[INFO] [world]: World=dm TeeIdx=0 addr=127.0.0.1:8300 Changing world to destination');
});

test('CTF grab drop and return logs are emitted for actual flag transitions', function () {
    $clock      = new TickHandler(100);
    $controller = new PvpGameController($clock, isTeamMode: true, isCaptureTheFlag: true);
    $world      = new TestWorldWithController($clock, new Map(__DIR__.'/../ctf1.map'), $controller);
    $redFlag    = $controller->getFlag(GameConstants::TEAM_RED);
    assert($redFlag !== null);
    $attacker = new PlayerTee;
    $world->addTee($attacker);
    $attacker->team = GameConstants::TEAM_BLUE;
    $character      = new PvpCharacterEntity($world, clone $redFlag->getPosition());
    $character->spawn(clone $redFlag->getPosition(), $attacker);
    $world->addEntity($character);

    $lines = captureGameLogs(function () use ($controller, $character, $attacker) {
        $controller->doTickDeferred();
        $character->die($attacker->teeIndex, GameConstants::WEAPON_SELF);
    });
    expect($lines)->toContain(
        '[DEBUG] [ctf]: World=test Flag grabbed Team=0 TeeIdx=0',
        '[DEBUG] [ctf]: World=test Flag dropped Team=0 TeeIdx=0',
    );

    $defender = new PlayerTee;
    $world->addTee($defender);
    $defender->team    = GameConstants::TEAM_RED;
    $defenderCharacter = new PvpCharacterEntity($world, clone $redFlag->getPosition());
    $defenderCharacter->spawn(clone $redFlag->getPosition(), $defender);
    $world->addEntity($defenderCharacter);

    $lines = captureGameLogs(fn () => $controller->doTickDeferred());
    expect($lines)->toBe(['[DEBUG] [ctf]: World=test Flag returned Team=0 TeeIdx=1']);
});
