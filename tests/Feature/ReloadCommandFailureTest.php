<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\ClCallVoteChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\System\MapChangeChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Server\ConnectionSlot;
use Tests\Support\RconTestCommand;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

test('reload command failures stop execution and preserve the active world', function (string $command, string $message, string $context) {
    $directory = sys_get_temp_dir().'/teeframe-reload-failure-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $path = $directory.'/current.map';
    copy(__DIR__.'/../dm1.map', $path);

    try {
        $server = new RconTestServer;
        $client = $server->addClient();
        $world  = $server->entryWorld;
        $map    = new Map($path);
        (new ReflectionProperty(AbstractWorld::class, 'map'))->setValue($world, $map);
        $controller = $world->getGameController();
        $tee        = $client->playerTee();
        $after      = new RconTestCommand('after_reload');
        $world->registerCommand($after);
        $line = $command.'; after_reload';
        if ($command === 'reload') {
            unlink($path);
        }

        if ($context === 'vote') {
            $world->getVoteController()->addVoteOption('Reload test', $line);
            $server->send($client, new ClCallVoteChunk('option', 'Reload test', ''));
            $server->socket($client)->packets = [];
            $world->getVoteController()->tick($world);
            $messages = array_map(fn (SvChatChunk $chunk): string => $chunk->text, $server->socket($client)->chunks(SvChatChunk::class));
            expect($messages)->toContain('Vote command failed: '.$message);
            expect($messages)->not->toContain('Vote passed');
            expect($world->getVoteController()->isVoteRunning())->toBeFalse();
        } else {
            $server->send($client, new RconAuthChunk('', 'test-secret', false));
            $server->socket($client)->packets = [];
            if ($context === 'forced vote') {
                $world->getVoteController()->addVoteOption('Reload test', $line);
                $line = 'force_vote option "Reload test"';
            }
            $server->send($client, new RconCmdChunk($line));
            $messages = array_map(fn (RconLineChunk $chunk): string => $chunk->line, $server->socket($client)->chunks(RconLineChunk::class));
            expect($messages)->toContain($message);
            if ($context === 'rcon') {
                expect($server->socket($client)->chunks(SvChatChunk::class))->toBeEmpty();
            }
        }

        $server->tick();
        expect($after->executions)->toBeEmpty();
        expect($world->getMap())->toBe($map);
        expect($world->getGameController())->toBe($controller);
        expect($world->getGameController()->getGameType())->toBe('DM');
        expect($client->state)->toBe(ConnectionSlot::STATE_INGAME);
        expect($client->playerTee())->toBe($tee);
        expect($server->socket($client)->chunks(MapChangeChunk::class))->toBeEmpty();
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with([
    ['change_map missing-map', 'Unable to change map to missing-map'],
    ['change_gamemode unknown', 'Unable to change gamemode to UNKNOWN'],
    ['change_gamemode ctf missing-map', 'Unable to change gamemode to CTF'],
    ['reload', 'Unable to reload world'],
])->with(['vote', 'rcon', 'forced vote']);
