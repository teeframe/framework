<?php

use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\ControlMessage;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Ban\Ban;
use TeeFrame\Server\Ban\BanList;
use Tests\Support\RconTestServer;

require_once __DIR__.'/../Support/RconTestServer.php';

test('BanList bans an address', function () {
    $banList = new BanList;

    $banList->ban('192.168.1.1', 600, 'Kicked by vote');

    expect($banList->getBan('192.168.1.1'))->not->toBeNull();
    expect($banList->getBan('192.168.1.2'))->toBeNull();
});

test('BanList getBan returns the ban entry', function () {
    $banList = new BanList;

    $banList->ban('10.0.0.1', 300, 'Test reason');

    $ban = $banList->getBan('10.0.0.1');
    assert($ban instanceof Ban);
    expect($ban->address)->toBe('10.0.0.1');
    expect($ban->reason)->toBe('Test reason');
    expect($ban->expiry)->toBeGreaterThan(time());
});

test('BanList getBan returns null for non-banned address', function () {
    $banList = new BanList;

    expect($banList->getBan('10.0.0.1'))->toBeNull();
});

test('BanList removes expired bans on cleanup', function () {
    $banList = new BanList;

    // Ban for 0 seconds — immediately expired
    $banList->ban('10.0.0.2', 0, 'Expired');

    // getBan triggers cleanup
    expect($banList->getBan('10.0.0.2'))->toBeNull();
});

test('permanent bans do not expire and IPv6 lookups use a canonical address', function () {
    $banList = new BanList;
    $banList->ban('2001:db8:0:0:0:0:0:1', null, 'Permanent');
    $ban = $banList->getBan('2001:db8::1');
    expect($ban)->not->toBeNull();
    assert($ban instanceof Ban);
    expect($ban->expiry)->toBeNull();
    expect($ban->isExpired(PHP_INT_MAX))->toBeFalse();
    $banList->unban('2001:db8:0:0:0:0:0:1');
    expect($banList->getBans())->toBeEmpty();
});

test('new connections use one ban lookup and reject only active bans', function (string $mode) {
    $server = new RconTestServer;
    $bans   = new class extends BanList
    {
        public int $lookups = 0;

        public function getBan(string $address): ?Ban
        {
            $this->lookups++;

            return parent::getBan($address);
        }
    };
    (new ReflectionProperty(AbstractServerInstance::class, 'banList'))->setValue($server, $bans);
    if ($mode !== 'none') {
        $bans->ban('127.0.0.1', match ($mode) {
            'permanent' => null,
            'expired'   => 0,
            default     => 60,
        }, 'Test reason');
    }

    $socket = $server->connect('127.0.0.1', 8300);
    $packet = $socket->packets[0];
    expect($packet)->toBeInstanceOf(ControlMessage::class);
    assert($packet instanceof ControlMessage);
    $blocked = $mode === 'active' || $mode === 'permanent';

    expect($bans->lookups)->toBe(1);

    expect($packet->getControlMessage())->toBe($blocked ? NetworkMessages::CONTROL_CLOSE : NetworkMessages::CONTROL_CONNECT_ACCEPT);
    expect($server->getConnections())->toHaveCount($blocked ? 0 : 1);
    if ($blocked) {
        expect($packet->getControlMessageExtra())->toContain('You are banned', 'Test reason');
    }
})->with(['active', 'permanent', 'expired', 'none']);

test('Ban isExpired returns true for past expiry', function () {
    $ban = new Ban('1.2.3.4', time() - 100, 'Past');

    expect($ban->isExpired(time()))->toBeTrue();
});

test('Ban isExpired returns false for future expiry', function () {
    $ban = new Ban('1.2.3.4', time() + 100, 'Future');

    expect($ban->isExpired(time()))->toBeFalse();
});
