<?php

namespace TeeFrame\Server;

use WeakMap;

use Carbon\Carbon;

class Console
{
    /** @var \WeakMap<\Closure, true>|null */
    protected static ?WeakMap $listeners = null;

    /** @param \Closure(string): void $listener */
    public static function listen(\Closure $listener): void
    {
        static::$listeners ??= new WeakMap;
        static::$listeners[$listener] = true;
    }

    public static function info(string $message): void
    {
        static::newLine($message, 32);
    }

    public static function warn(string $message): void
    {
        static::newLine($message, 33);
    }

    public static function error(string $message): void
    {
        static::newLine($message, 31);
    }

    protected static function newLine(string $message, int $textColor = 0): void
    {
        echo '['.Carbon::now()->toDateTimeString().']'.' '."\033[0;{$textColor}m".$message."\033[0m".PHP_EOL;

        foreach (static::$listeners ?? [] as $listener => $_) {
            $listener($message);
        }
    }
}
