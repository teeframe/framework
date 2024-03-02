<?php

namespace TeeFrame\Server\Server;

use TeeFrame\Server\Connection\ConnectionSlot;

class ServerInstance
{
    public static ServerSocket $socket;

    // TODO: Type this

    /**
     * Statically typing this method.
     *
     * @return array<int, ConnectionSlot>
     */
    public static function getConnectionSlots(): array
    {
        return self::$socket->getConnectionSlots();
    }

    public static function __callStatic(mixed $name, mixed $arguments): mixed
    {
        if (method_exists(self::$socket, $name)) {
            return self::$socket->$name(...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist");
    }
}
