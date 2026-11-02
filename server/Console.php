<?php

namespace TeeFrame\Server;

use Carbon\Carbon;
use WeakMap;

class Console
{
    public const LEVEL_NONE  = -1;
    public const LEVEL_ERROR = 0;
    public const LEVEL_WARN  = 1;
    public const LEVEL_INFO  = 2;
    public const LEVEL_DEBUG = 3;
    public const LEVEL_TRACE = 4;

    /** @var WeakMap<\Closure, int>|null */
    protected static ?WeakMap $listeners = null;

    protected static int $outputLevel = self::LEVEL_INFO;
    protected static ?bool $colors    = null;

    /** @var resource|null */
    protected static $logFile          = null;
    protected static int $logFileLevel = self::LEVEL_INFO;

    /** @param \Closure(string): void $listener */
    public static function listen(\Closure $listener, int $level = self::LEVEL_INFO): void
    {
        static::validateLevel($level);
        static::$listeners ??= new WeakMap;
        static::$listeners[$listener] = $level;
    }

    /** @param \Closure(string): void $listener */
    public static function unlisten(\Closure $listener): void
    {
        unset(static::$listeners[$listener]);
    }

    public static function setOutputLevel(int $level): void
    {
        static::validateLevel($level);
        static::$outputLevel = $level;
    }

    public static function setColors(?bool $enabled): void
    {
        static::$colors = $enabled;
    }

    public static function setLogFile(?string $path, int $level = self::LEVEL_INFO): void
    {
        static::validateLevel($level);
        $file = $path === null ? null : static::openLogFile($path);

        if (static::$logFile !== null) {
            fclose(static::$logFile);
        }

        static::$logFile      = $file;
        static::$logFileLevel = $level;
    }

    public static function info(string $system, string $message): void
    {
        static::newLine($system, $message, self::LEVEL_INFO);
    }

    public static function warn(string $system, string $message): void
    {
        static::newLine($system, $message, self::LEVEL_WARN);
    }

    public static function error(string $system, string $message): void
    {
        static::newLine($system, $message, self::LEVEL_ERROR);
    }

    public static function debug(string $system, string $message): void
    {
        static::newLine($system, $message, self::LEVEL_DEBUG);
    }

    public static function trace(string $system, string $message): void
    {
        static::newLine($system, $message, self::LEVEL_TRACE);
    }

    protected static function newLine(string $system, string $message, int $level): void
    {
        [$label, $color] = match ($level) {
            self::LEVEL_ERROR => ['ERROR', 31],
            self::LEVEL_WARN  => ['WARN', 33],
            self::LEVEL_INFO  => ['INFO', 32],
            self::LEVEL_DEBUG => ['DEBUG', 36],
            self::LEVEL_TRACE => ['TRACE', 90],
            default           => throw new \InvalidArgumentException('Invalid console message level: '.$level),
        };

        $line            = '['.$label.'] ['.static::escape($system).']: '.static::escape($message);
        $timestampedLine = '['.Carbon::now()->toDateTimeString().'] '.$line;

        static::writeToTerminal($timestampedLine, $level, $color);
        static::writeToLogFile($timestampedLine, $level);
        static::notifyListeners($line, $level);
    }

    protected static function writeToTerminal(string $line, int $level, int $color): void
    {
        if ($level > static::$outputLevel) {
            return;
        }

        if (static::shouldUseColors()) {
            $line = "\033[0;{$color}m".$line."\033[0m";
        }

        echo $line.PHP_EOL;
    }

    protected static function shouldUseColors(): bool
    {
        if (static::$colors !== null) {
            return static::$colors;
        }

        return getenv('NO_COLOR') === false
            && defined('STDOUT')
            && stream_isatty(STDOUT);
    }

    protected static function writeToLogFile(string $line, int $level): void
    {
        if (static::$logFile === null || $level > static::$logFileLevel) {
            return;
        }

        $data = $line.PHP_EOL;

        if (@fwrite(static::$logFile, $data) === strlen($data)) {
            return;
        }

        static::setLogFile(null);
        error_log('Could not write to the console log file; file logging disabled');
    }

    /** @return resource */
    protected static function openLogFile(string $path)
    {
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $file = fopen($path, 'ab');
        } finally {
            restore_error_handler();
        }

        if ($file === false) {
            throw new \RuntimeException('Cannot open console log file: '.$path);
        }

        return $file;
    }

    protected static function notifyListeners(string $line, int $level): void
    {
        foreach (static::$listeners ?? [] as $listener => $outputLevel) {
            if ($level <= $outputLevel) {
                $listener($line);
            }
        }
    }

    protected static function validateLevel(int $level): void
    {
        if ($level < self::LEVEL_NONE || $level > self::LEVEL_TRACE) {
            throw new \InvalidArgumentException('Invalid console output level: '.$level);
        }
    }

    protected static function escape(string $text): string
    {
        // Player names and network errors must not inject terminal commands or log lines.
        return preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static fn (array $match): string => sprintf('\\x%02x', ord($match[0])),
            $text,
        ) ?? '';
    }
}
