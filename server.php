<?php

require_once 'vendor/autoload.php';

use Base\Server\ServerSocket;

$server = new ServerSocket('127.0.0.1', 8304);

$server->on('packet', function (ServerSocket $server, string $data, array $clientInfo): void {
    $server->onPacket($data, $clientInfo);
});

$server->start();
