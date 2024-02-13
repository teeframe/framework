<?php

namespace Base;

class Console
{
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
        echo "\033[0;{$textColor}m".$message."\033[0m".PHP_EOL;
    }
}
