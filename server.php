<?php

require_once 'vendor/autoload.php';

use Base\ServerSocket;

$server = new ServerSocket('127.0.0.1', 8304);

        /*
         * Start & Shutdown functions
         */
        $server->on('start', fn (ServerSocket $server) => $this->openServer($server));
        $server->on('shutdown', fn (ServerSocket $server) => $this->closeServer($server));

        /*
         * On client packet
         */
        $server->on(
            event_name: 'packet',
            callback: fn (ServerSocket $server, string $data, array $clientInfo): NetworkPacket => new NetworkPacket(
                $server,
                array_values(unpack('C*', $data)),
                (object) $clientInfo,
            )
        );

        /*
         * Start server
         */
        $server->start();