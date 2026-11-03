<?php

namespace TeeFrame\Server\Sockets;

use Swoole\Server;

/**
 * @property string $host
 * @property int $port
 * @property int $type
 */
abstract class AbstractSocket extends Server {}
