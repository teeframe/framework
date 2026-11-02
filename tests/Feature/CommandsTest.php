<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\PlayerTee;
use TeeFrame\Game\World\TuneController;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\ClSayChunk;
use TeeFrame\Network\Chunks\Game\ClSetTeamChunk;
use TeeFrame\Network\Chunks\Game\ClVoteChunk;
use TeeFrame\Network\Chunks\Game\SvBroadcastChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvTuneParamsChunk;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\ConnectionSlot;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;
use Tests\Support\RconTestSocket;

require_once __DIR__.'/../Support/RconTestServer.php';

/** @return array{RconTestServer, ConnectionSlot} */
function commandTestServer(): array
{
    $server = new RconTestServer;
    $admin  = $server->addClient(address: '192.0.2.1');
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));
    $server->socket($admin)->packets = [];

    return [$server, $admin];
}

/** @return list<string> */
function commandReplyLines(RconTestSocket $socket): array
{
    return array_map(fn (RconLineChunk $chunk): string => $chunk->line, $socket->chunks(RconLineChunk::class));
}

test('default chat helpers reply privately and do not expose addresses or server commands', function (string $line, string $expected) {
    [$server, $admin]                = commandTestServer();
    $guest                           = $server->addClient(address: '192.0.2.2');
    $guest->playerTee()->name        = 'Guest';
    $server->socket($admin)->packets = [];

    $server->send($guest, new ClSayChunk(false, $line));

    $texts = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($guest)->chunks(SvChatChunk::class));
    expect(implode("\n", $texts))->toContain($expected);
    expect(implode("\n", $texts))->not->toContain('192.0.2.', '/shutdown', '/ban ');
    expect($server->socket($admin)->chunks(SvChatChunk::class))->toBeEmpty();
})->with([
    ['/help', 'Use cmdlist to list commands, or help <command> to show usage.'],
    ['/cmdlist', 'cmdlist'],
    ['/help w', '/whisper s[player] r[message]'],
    ['/help shutdown', 'Command not found'],
    ['/list Guest', '1: Guest'],
    ['/info', 'Gametype=DM'],
    ['/rules', 'No rules configured.'],
    ['/settings collision', 'Player collision is enabled'],
]);

test('chat cmdlist contains only sorted command names and aliases', function () {
    [$server, $admin] = commandTestServer();
    $server->send($admin, new ClSayChunk(false, '/cmdlist'));
    $chunks = $server->socket($admin)->chunks(SvChatChunk::class);
    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->text)->toBe('c, cmdlist, converse, emote, help, info, list, ping, rules, settings, w, whisper');
});

test('chat cmdlist sends every command when the list spans several messages', function () {
    [$server, $admin] = commandTestServer();
    $expected         = ['c', 'cmdlist', 'converse', 'emote', 'help', 'info', 'list', 'ping', 'rules', 'settings', 'w', 'whisper'];
    foreach (range(1, 70) as $index) {
        $name = sprintf('chat_command_%03d', $index);
        $server->entryWorld->registerCommand(new RconTestCommand($name, type: AbstractCommand::TYPE_CHAT));
        $expected[] = $name;
    }
    sort($expected, SORT_STRING | SORT_FLAG_CASE);
    $server->send($admin, new ClSayChunk(false, '/cmdlist'));
    $lines = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($admin)->chunks(SvChatChunk::class));
    expect(count($lines))->toBeGreaterThan(1);
    expect(explode(', ', implode(', ', $lines)))->toBe($expected);
});

test('chat help without parameters shows guidance and cmdlist does not accept parameters', function (string $line, string $expected) {
    [$server, $admin] = commandTestServer();
    $server->send($admin, new ClSayChunk(false, $line));
    $chunks = $server->socket($admin)->chunks(SvChatChunk::class);
    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->text)->toBe($expected);
})->with([
    ['/help', 'Use cmdlist to list commands, or help <command> to show usage.'],
    ['/help   ', 'Use cmdlist to list commands, or help <command> to show usage.'],
    ['/help ping extra', 'Usage: /help ?s[command]'],
    ['/cmdlist ping', 'Usage: /cmdlist'],
]);

test('chat help includes the alias parameter signature', function () {
    [$server, $admin] = commandTestServer();
    $server->send($admin, new ClSayChunk(false, '/help c'));
    expect($server->socket($admin)->chunks(SvChatChunk::class)[0]->text)->toBe('/converse r[message] - Whisper to the last player you whispered to - Aliases: c r[message]');
});

test('RCON help and autocomplete descriptions include alias parameter signatures', function () {
    [$server, $admin] = commandTestServer();
    $server->send($admin, new RconCmdChunk('help muteip'));
    $description = 'Mute chat and whispers by IP address or world-local player id - Aliases: muteip s[ip or id] i[seconds] ?r[reason]';
    expect(commandReplyLines($server->socket($admin)))->toContain('muteip s[ip or id] i[seconds] ?r[reason] - '.$description);
    $info = $server->getRcon()->getCommandInfo($server->entryWorld);
    expect($info['muteid']['help'])->toBe($description);
    expect($info['muteip']['help'])->toBe($description);
    expect($info['reload']['help'])->toBe('Reload the current map and restart this world');
});

test('all framework commands have descriptions beyond their command names', function () {
    [$server] = commandTestServer();
    foreach ($server->entryWorld->getCommands() as $command) {
        expect(trim($command->getDescription()))->not->toBe('');
        expect(strcasecmp($command->getDescription(), $command->getName()))->not->toBe(0);
    }
});

test('rules are supplied by the world instead of hard-coded game rules', function () {
    $world = new class(new TickHandler(100), new Map) extends TestWorld
    {
        public function getRules(): array
        {
            return ['Rule one', 'Rule two'];
        }
    };
    $tee = new PlayerTee;
    $world->addTee($tee);
    recordingServer($world)->clear();

    $world->onMessage($tee, new ClSayChunk(false, '/rules'));

    expect(array_map(function ($chunk): string {
        assert($chunk instanceof SvChatChunk);

        return $chunk->text;
    }, recordingServer($world)->sentChunks))->toBe(['Rule one', 'Rule two']);
});

test('converse follows the last whispered player through renames but not index reuse', function () {
    [$server, $sender]         = commandTestServer();
    $target                    = $server->addClient(address: '192.0.2.2');
    $target->playerTee()->name = 'Target';
    $server->send($sender, new ClSayChunk(false, '/w Target secret'));
    $target->playerTee()->name         = 'Renamed';
    $sender->playerTee()->lastChatTick = 0;
    $server->send($sender, new ClSayChunk(false, '/c second secret'));
    expect(array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($target)->chunks(SvChatChunk::class)))->toBe(['secret', 'second secret']);

    $target->closeConnection('Leaving');
    $replacement = $server->addClient(address: '192.0.2.3');
    expect($replacement->playerTee()->teeIndex)->toBe(1);
    $sender->playerTee()->lastChatTick = 0;
    $server->send($sender, new ClSayChunk(false, '/c must not leak'));
    expect($server->socket($replacement)->chunks(SvChatChunk::class))->toBeEmpty();
    expect(array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($sender)->chunks(SvChatChunk::class)))->toContain('No ongoing conversation. Whisper to someone first.');
});

test('emote command defaults to no expiration and cannot change expressions during a pause', function () {
    $clock = new TickHandler(100);
    $world = new TestWorld($clock, new Map);
    $tee   = new PlayerTee;
    $world->addTee($tee);
    $character = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $tee);
    $character->setEmote(GameConstants::EMOTE_PAIN, $clock->getCurrentTick() + 1);
    $world->onMessage($tee, new ClSayChunk(false, '/emote happy'));
    expect($character->emote)->toBe(GameConstants::EMOTE_HAPPY);
    for ($i = 0; $i <= NetworkParams::TICKS_PER_SECOND; $i++) {
        $clock->advanceTick();
    }
    $character->doSnap($tee);
    expect($character->emote)->toBe(GameConstants::EMOTE_HAPPY);

    $world->setPaused(true);
    $tee->lastChatTick = 0;
    $world->onMessage($tee, new ClSayChunk(false, '/emote angry'));
    expect($character->emote)->toBe(GameConstants::EMOTE_HAPPY);
});

test('server announcements stay in their world and use the appropriate message type', function (string $name, bool $broadcast) {
    $class            = $broadcast ? SvBroadcastChunk::class : SvChatChunk::class;
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '192.0.2.2');
    $other            = $server->addClient($server->addWorld('other'), '192.0.2.3');
    $server->send($admin, new RconCmdChunk($name.' "World announcement"'));

    expect($server->socket($guest)->chunks($class))->toHaveCount(1);
    expect($server->socket($guest)->chunks($class)[0]->text)->toBe('World announcement');
    expect($server->socket($other)->packets)->toBeEmpty();
})->with([
    ['say', false],
    ['broadcast', true],
]);

test('status tuning and help output goes only to the requesting RCON session', function () {
    [$server, $admin] = commandTestServer();
    $other            = $server->addClient(address: '192.0.2.2');
    $server->send($other, new RconAuthChunk('', 'test-secret', false));
    $server->getRcon()->update();
    $server->socket($other)->packets = [];
    $server->socket($admin)->packets = [];

    $server->send($admin, new RconCmdChunk('status; tune gravity; help kick; uptime'));
    $server->getRcon()->update();
    $lines = implode("\n", commandReplyLines($server->socket($admin)));
    expect($lines)->toContain('addr=192.0.2.2', 'gravity 0.50', 'kick i[id]', 'Uptime:');
    expect(implode("\n", commandReplyLines($server->socket($other))))->not->toContain('addr=', 'gravity 0.50', 'kick i[id]', 'Uptime:');
    expect($server->socket($other)->chunks(SvChatChunk::class))->toBeEmpty();
});

test('tuning changes use normal units update clients including ready clients and leave other worlds unchanged', function () {
    [$server, $admin]    = commandTestServer();
    $guest               = $server->addClient(address: '192.0.2.2');
    $ready               = $server->addClient(address: '192.0.2.3', state: ConnectionSlot::STATE_READY);
    $ready->readyToEnter = true;
    $loading             = $server->addClient(address: '192.0.2.4', state: ConnectionSlot::STATE_LOADING);
    $other               = $server->addClient($server->addWorld('other'), '192.0.2.5');
    $tuning              = $server->entryWorld->getTuneController();

    $server->send($admin, new RconCmdChunk('tune gravity 0.75'));
    expect($tuning->gravity)->toBe(75);
    foreach ([$guest, $ready] as $client) {
        expect($server->socket($client)->chunks(SvTuneParamsChunk::class)[0]->gravity)->toBe(75);
    }
    expect($server->socket($loading)->packets)->toBeEmpty();
    expect($server->socket($other)->packets)->toBeEmpty();
    expect($other->world()->getTuneController()->gravity)->toBe(50);
    $server->send($admin, new RconCmdChunk('toggle_tune gravity 0.75 0.25'));
    expect($tuning->gravity)->toBe(25);
    $server->send($admin, new RconCmdChunk('toggle_tune gravity 0.75 0.25; tune_reset gravity'));
    expect($tuning->gravity)->toBe(50);
    $server->send($admin, new RconCmdChunk('tune ground_control_speed 12; tune_reset; tunes'));
    expect($tuning)->toEqual(new TuneController);
    expect(commandReplyLines($server->socket($admin)))->toContain('ground_control_speed 10.00');
});

test('invalid tuning commands leave the existing values intact', function (string $line) {
    [$server, $admin] = commandTestServer();
    $server->send($admin, new RconCmdChunk($line));
    expect($server->entryWorld->getTuneController())->toEqual(new TuneController);
    expect(commandReplyLines($server->socket($admin)))->not->toBeEmpty();
})->with(['tune missing 5', 'tune gravity 21474836.48', 'tune gravity -21474836.49', 'tune gravity NaN', 'tune_reset missing']);

test('kick disconnects without a ban and refuses self and authenticated administrators', function () {
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '192.0.2.2');
    $otherAdmin       = $server->addClient(address: '192.0.2.3');
    $server->send($otherAdmin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('kick 0'));
    $server->send($admin, new RconCmdChunk('kick 2'));
    $server->send($admin, new RconCmdChunk('kick 1 "Test reason"'));

    expect($guest->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->getBanList()->getBan('192.0.2.2'))->toBeNull();
    expect($admin->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($otherAdmin->state)->toBe(ConnectionSlot::STATE_INGAME);
    $close = array_find($server->socket($guest)->packets, fn ($packet): bool => $packet instanceof ControlMessage);
    assert($close instanceof ControlMessage);
    expect(rtrim($close->getControlMessageExtra(), "\0"))->toBe('Test reason');
});

test('IP bans apply across worlds support permanent duration and can be removed by index or address', function () {
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '2001:db8::2');
    $other            = $server->addClient($server->addWorld('other'), '2001:db8:0:0:0:0:0:2');
    $server->send($admin, new RconCmdChunk('ban 1 0 "Permanent test"; bans'));
    expect($guest->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($other->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->getBanList()->getBan('2001:db8::2')?->expiry)->toBeNull();
    expect($server->getBanList()->getBan('2001:db8:0:0:0:0:0:2'))->not->toBeNull();
    expect(implode("\n", commandReplyLines($server->socket($admin))))->toContain('2001:db8::2 (permanent)');
    $reply = $server->connect('2001:db8::2', 8301)->packets[0];
    assert($reply instanceof ControlMessage);
    expect($reply->getControlMessageExtra())->toContain('permanently');

    $server->send($admin, new RconCmdChunk('unban 0; ban 198.51.100.5; unban 198.51.100.5; ban 198.51.100.6 1; unban_all'));
    expect($server->getBanList()->getBans())->toBeEmpty();
});

test('ban protects an administrator address even when another player shares it in another world', function (string $target) {
    [$server, $admin] = commandTestServer();
    $shared           = $server->addClient(address: '192.0.2.2');
    $otherAdmin       = $server->addClient($server->addWorld('other'), '192.0.2.2');
    $server->send($otherAdmin, new RconAuthChunk('', 'test-secret', false));
    $server->send($admin, new RconCmdChunk('ban '.$target.' 5'));
    expect($server->getBanList()->getBans())->toBeEmpty();
    expect($shared->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($otherAdmin->state)->toBe(ConnectionSlot::STATE_INGAME);
})->with(['1', '192.0.2.1', '192.0.2.2']);

test('chat mutes block public team and private messages across reconnects until removed', function (string $message, bool $team) {
    [$server, $admin]         = commandTestServer();
    $admin->playerTee()->name = 'Admin';
    $guest                    = $server->addClient(address: '192.0.2.2');
    $server->send($admin, new RconCmdChunk('muteid 1 60 "Spam"; mutes'));
    $server->socket($admin)->packets = [];
    $server->send($guest, new ClSayChunk($team, $message));
    expect($server->socket($admin)->chunks(SvChatChunk::class))->toBeEmpty();
    expect($server->socket($guest)->chunks(SvChatChunk::class)[0]->text)->toContain('You are muted', 'Spam');
    $guest->closeConnection('Reconnecting');
    $guest = $server->addClient(address: '192.0.2.2');
    $server->send($guest, new ClSayChunk($team, $message));
    expect($server->socket($guest)->chunks(SvChatChunk::class)[0]->text)->toContain('You are muted');
    $server->send($admin, new RconCmdChunk('unmuteip 192.0.2.2'));
    $server->socket($admin)->packets = [];
    $server->send($guest, new ClSayChunk(false, 'allowed'));
    expect($server->socket($admin)->chunks(SvChatChunk::class)[0]->text)->toBe('allowed');
})->with([['public text', false], ['team text', true], ['/w Admin secret', false], ['/c secret', false]]);

test('locked teams reject player requests but allow spectating and forced transitions', function () {
    [$server, $admin]                                     = commandTestServer();
    $guest                                                = $server->addClient(address: '192.0.2.2');
    $server->entryWorld->getGameController()->teamsLocked = true;
    $server->send($guest, new ClSetTeamChunk(-1));
    expect($guest->playerTee()->team)->toBe(-1);
    $guest->playerTee()->lastSetTeam = 0;
    $server->send($guest, new ClSetTeamChunk(0));
    expect($guest->playerTee()->team)->toBe(-1);
    $guest->playerTee()->setTeam(GameConstants::TEAM_RED);
    expect($guest->playerTee()->team)->toBe(0);
});

test('pause toggles active play but respects the end-of-round freeze', function () {
    [$server, $admin] = commandTestServer();
    $world            = $server->entryWorld;
    $controller       = $world->getGameController();
    assert($controller instanceof PvpGameController);
    $server->send($admin, new RconCmdChunk('pause'));
    expect($world->isPaused())->toBeTrue();
    $server->send($admin, new RconCmdChunk('pause_game'));
    expect($world->isPaused())->toBeFalse();

    $controller->endRound();
    $server->send($admin, new RconCmdChunk('pause'));
    expect($world->isPaused())->toBeTrue();
});

test('clear_votes updates ingame and already initialized ready menus without changing another world', function () {
    [$server, $admin]    = commandTestServer();
    $world               = $server->entryWorld;
    $ready               = $server->addClient(address: '192.0.2.2', state: ConnectionSlot::STATE_READY);
    $ready->readyToEnter = true;
    $other               = $server->addClient($server->addWorld('other'), '192.0.2.3');
    $world->getVoteController()->addVoteOption('Reload', 'reload');
    $other->world()->getVoteController()->addVoteOption('Other', 'reload');
    $server->send($admin, new RconCmdChunk('votes; clear_votes'));
    expect(commandReplyLines($server->socket($admin)))->toContain('Reload - reload');
    expect($world->getVoteController()->getVoteOptions())->toBeEmpty();
    expect($server->socket($ready)->chunks(SvVoteClearOptionsChunk::class))->toHaveCount(1);
    expect($other->world()->getVoteController()->getVoteOptions())->toHaveCount(1);
    expect($server->socket($other)->packets)->toBeEmpty();
});

test('server vote yes and no override player majorities', function (bool $yes) {
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '192.0.2.2');
    $command          = new RconTestCommand;
    $world            = $server->entryWorld;
    $world->registerCommand($command);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Action', 'record');
    $server->send($guest, new ClCallVoteChunk('option', 'Action', ''));
    $server->send($admin, new RconCmdChunk('vote '.($yes ? 'yes' : 'no')));
    $votes->requestUpdate();
    $votes->tick($world);
    expect($command->executions)->toHaveCount($yes ? 1 : 0);
    expect($votes->isVoteRunning())->toBeFalse();
})->with([false, true]);

test('normal kick votes reject authenticated administrators without starting a poll or banning them', function () {
    [$server, $admin]                 = commandTestServer();
    $caller                           = $server->addClient(address: '192.0.2.2');
    $other                            = $server->addClient(address: '192.0.2.3');
    $votes                            = $server->entryWorld->getVoteController();
    $server->socket($admin)->packets  = [];
    $server->socket($caller)->packets = [];
    $server->socket($other)->packets  = [];

    $server->send($caller, new ClCallVoteChunk('kick', (string) $admin->playerTee()->teeIndex, 'Test'));
    $server->send($other, new ClVoteChunk(1));
    $votes->tick($server->entryWorld);

    expect($votes->isVoteRunning())->toBeFalse();
    expect($admin->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($admin->rcon()->authenticated)->toBeTrue();
    expect($server->getBanList()->getBan('192.0.2.1'))->toBeNull();
    expect($server->socket($caller)->chunks(SvChatChunk::class)[0]->text)->toBe("You can't kick an authenticated administrator");
    expect($server->socket($admin)->chunks(SvChatChunk::class))->toBeEmpty();
    expect($server->socket($other)->chunks(SvChatChunk::class))->toBeEmpty();
});

test('a kick-vote target that authenticates during the poll remains connected and unbanned', function () {
    $server = new RconTestServer;
    $target = $server->addClient(address: '192.0.2.1');
    $caller = $server->addClient(address: '192.0.2.2');
    $other  = $server->addClient(address: '192.0.2.3');
    $votes  = $server->entryWorld->getVoteController();
    $server->send($caller, new ClCallVoteChunk('kick', (string) $target->playerTee()->teeIndex, 'Test'));
    expect($votes->isVoteRunning())->toBeTrue();

    $server->send($target, new RconAuthChunk('', 'test-secret', false));
    $server->send($other, new ClVoteChunk(1));
    $server->socket($caller)->packets = [];
    $votes->tick($server->entryWorld);

    expect($votes->isVoteRunning())->toBeFalse();
    expect($target->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($target->rcon()->authenticated)->toBeTrue();
    expect($server->getBanList()->getBan('192.0.2.1'))->toBeNull();
    $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($caller)->chunks(SvChatChunk::class));
    expect($messages)->toContain('Vote command failed: Authenticated administrators cannot be kicked by vote');
    expect($messages)->not->toContain('Vote passed');
});

test('kick-vote administrator checks use the target player rather than an index in another world', function () {
    $server = new RconTestServer;
    $target = $server->addClient(address: '192.0.2.1');
    $caller = $server->addClient(address: '192.0.2.2');
    $other  = $server->addClient(address: '192.0.2.3');
    $admin  = $server->addClient($server->addWorld('other'), '192.0.2.4');
    expect($admin->playerTee()->teeIndex)->toBe($target->playerTee()->teeIndex);
    $server->send($admin, new RconAuthChunk('', 'test-secret', false));

    $server->send($caller, new ClCallVoteChunk('kick', (string) $target->playerTee()->teeIndex, 'Test'));
    $server->send($other, new ClVoteChunk(1));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);

    expect($target->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($server->getBanList()->getBan('192.0.2.1'))->not->toBeNull();
    expect($admin->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($admin->rcon()->authenticated)->toBeTrue();
    expect($server->getBanList()->getBan('192.0.2.4'))->toBeNull();
});

test('forcing a result cannot revive a vote invalidated by a disconnected target', function () {
    [$server, $admin] = commandTestServer();
    $target           = $server->addClient(address: '192.0.2.2');
    $votes            = $server->entryWorld->getVoteController();
    $server->send($admin, new ClCallVoteChunk('kick', '1', ''));
    $target->closeConnection('Leaving');
    $replacement = $server->addClient(address: '192.0.2.3');
    $server->send($admin, new RconCmdChunk('vote yes'));
    $votes->tick($server->entryWorld);
    expect($replacement->state)->toBe(ConnectionSlot::STATE_INGAME);
    expect($votes->isVoteRunning())->toBeFalse();
});

test('new server commands are not implicit callable vote options', function (string $value) {
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '192.0.2.2');
    $server->send($guest, new ClCallVoteChunk('option', $value, ''));
    $server->entryWorld->getVoteController()->tick($server->entryWorld);
    expect($server->shutdownRequested)->toBeFalse();
    expect($server->entryWorld->getVoteController()->isVoteRunning())->toBeFalse();
})->with(['shutdown', 'clear_votes', 'unban_all', 'pause', 'bans', 'status', 'reload']);

test('unresolved vote commands report execution failure without executing any part of the option', function (string $line) {
    [$server, $admin] = commandTestServer();
    $world            = $server->entryWorld;
    $command          = new RconTestCommand;
    $world->registerCommand($command);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Broken option', $line);
    $server->send($admin, new ClCallVoteChunk('option', 'Broken option', ''));
    expect($votes->isVoteRunning())->toBeTrue();
    $server->socket($admin)->packets = [];

    $votes->tick($world);

    expect($votes->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBeEmpty();
    $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($admin)->chunks(SvChatChunk::class));
    expect($messages)->toContain('Vote command failed: Vote option does not resolve to valid server commands');
    expect($messages)->not->toContain('Vote passed');
})->with(['', 'missing_command', 'tune', 'tune gravity invalid', 'record extra', 'record; missing_command', 'record "unclosed']);

test('vote execution revalidates commands whose name type or parameters changed during the poll', function (string $change) {
    [$server, $admin] = commandTestServer();
    $world            = $server->entryWorld;
    $command          = new RconTestCommand;
    $world->registerCommand($command);
    $votes = $world->getVoteController();
    $votes->addVoteOption('Action', 'record');
    $server->send($admin, new ClCallVoteChunk('option', 'Action', ''));
    expect($votes->isVoteRunning())->toBeTrue();
    match ($change) {
        'name'   => $command->name   = 'renamed',
        'type'   => $command->type   = AbstractCommand::TYPE_CHAT,
        'params' => $command->params = 'i[number]',
        default  => throw new LogicException('Unknown command mutation: '.$change),
    };
    $server->socket($admin)->packets = [];

    $votes->tick($world);

    expect($votes->isVoteRunning())->toBeFalse();
    expect($command->executions)->toBeEmpty();
    $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($admin)->chunks(SvChatChunk::class));
    expect($messages)->toContain('Vote command failed: Vote option does not resolve to valid server commands');
    expect($messages)->not->toContain('Vote passed');
})->with(['name', 'type', 'params']);

test('failed semantic validation in a passed vote does not crash the server', function () {
    [$server, $admin] = commandTestServer();
    $world            = $server->entryWorld;
    $world->getVoteController()->addVoteOption('Bad tune', 'tune missing 5');
    $server->send($admin, new ClCallVoteChunk('option', 'Bad tune', ''));
    $world->getVoteController()->tick($world);
    expect($world->getVoteController()->isVoteRunning())->toBeFalse();
    expect(array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($admin)->chunks(SvChatChunk::class)))->toContain('Vote command failed: Unknown tuning parameter: missing');
});

test('kill_pl uses the normal death lifecycle and rejects paused kills', function () {
    [$server, $admin] = commandTestServer();
    $guest            = $server->addClient(address: '192.0.2.2');
    $world            = $server->entryWorld;
    $character        = new PvpCharacterEntity($world, new Vector2(64, 64));
    $character->spawn(new Vector2(64, 64), $guest->playerTee());
    $world->addEntity($character);
    $world->setPaused(true);
    $server->send($admin, new RconCmdChunk('kill_pl 1'));
    expect($character->alive)->toBeTrue();
    $world->setPaused(false);
    $server->send($admin, new RconCmdChunk('kill_pl 1 Test'));
    expect($character->alive)->toBeFalse();
    expect($guest->playerTee()->character)->toBeNull();
    expect($guest->state)->toBe(ConnectionSlot::STATE_INGAME);
});

test('automatic map votes are world scoped callable and not duplicated', function () {
    [$server, $admin] = commandTestServer();
    $world            = $server->entryWorld;
    $other            = $server->addWorld('other');
    $server->send($admin, new RconCmdChunk('add_map_votes'));
    $votes   = $world->getVoteController();
    $options = $votes->getVoteOptions();
    expect(array_map(fn ($option): string => $option->description, $options))->toContain('Map: dm1', 'Map: ctf1');
    foreach ($options as $option) {
        expect($votes->resolveCommands($world, $option->command))->not->toBeNull();
    }
    $server->send($admin, new RconCmdChunk('add_map_votes'));
    expect($votes->getVoteOptions())->toHaveCount(count($options));
    expect($other->getVoteController()->getVoteOptions())->toBeEmpty();
});

test('server command aliases are advertised and echo output is private', function () {
    [$server, $admin] = commandTestServer();
    $other            = $server->addClient(address: '192.0.2.2');
    expect(array_keys($server->getRcon()->getCommandInfo($server->entryWorld)))->toContain('pause_game', 'tunes', 'muteip', 'unmuteip');
    $server->send($admin, new RconCmdChunk('echo "A private line"'));
    expect(commandReplyLines($server->socket($admin)))->toContain('A private line');
    expect(commandReplyLines($server->socket($other)))->not->toContain('A private line');
});

test('shutdown is authenticated RCON only unless explicitly configured as a vote option and closes all worlds', function () {
    [$server, $admin] = commandTestServer();
    $other            = $server->addClient($server->addWorld('other'), '192.0.2.2');
    $server->send($other, new RconCmdChunk('shutdown'));
    expect($server->shutdownRequested)->toBeFalse();
    $server->send($admin, new RconCmdChunk('shutdown "Maintenance"'));
    expect($server->shutdownRequested)->toBeTrue();
    expect($admin->state)->toBe(ConnectionSlot::STATE_CLOSED);
    expect($other->state)->toBe(ConnectionSlot::STATE_CLOSED);
});
