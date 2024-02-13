<?php

namespace Base\Server;

class ServerInstance
{
    public static ServerSocket $socket;

    public static function __callStatic(mixed $name, mixed $arguments): mixed
    {
        if (method_exists(self::$socket, $name)) {
            return self::$socket->$name(...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist");
    }
}
