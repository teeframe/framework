<?php

use Carbon\Carbon;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Server\AbstractServerInstance;
use TeeFrame\Server\Console;

class ConsoleTestServer extends AbstractServerInstance
{
    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        throw new LogicException('Console test server does not accept connections');
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:34:56');
    Console::setOutputLevel(Console::LEVEL_INFO);
    Console::setColors(false);
});

afterEach(function () {
    Carbon::setTestNow();
    Console::setOutputLevel(Console::LEVEL_INFO);
    Console::setColors(null);
    Console::setLogFile(null);
});

/** @param Closure(): void $action */
function captureConsoleOutput(Closure $action): string
{
    ob_start();
    try {
        $action();

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

test('console output identifies severity and subsystem without relying on colors', function () {
    $output = captureConsoleOutput(function () {
        Console::info('server', 'Listening');
        Console::warn('register', 'Retrying registration');
        Console::error('network', 'Connection failed');
    });

    expect($output)->toBe(
        '[2026-11-02 12:34:56] [INFO] [server]: Listening'.PHP_EOL
        .'[2026-11-02 12:34:56] [WARN] [register]: Retrying registration'.PHP_EOL
        .'[2026-11-02 12:34:56] [ERROR] [network]: Connection failed'.PHP_EOL,
    );
});

test('console verbosity includes only messages up to the selected level', function (int $level, array $labels) {
    Console::setOutputLevel($level);
    $output = captureConsoleOutput(function () {
        Console::error('server', 'error');
        Console::warn('server', 'warn');
        Console::info('server', 'info');
        Console::debug('server', 'debug');
        Console::trace('server', 'trace');
    });

    preg_match_all('/\[(ERROR|WARN|INFO|DEBUG|TRACE)\]/', $output, $matches);
    expect($matches[1])->toBe($labels);
})->with([
    [Console::LEVEL_NONE, []],
    [Console::LEVEL_ERROR, ['ERROR']],
    [Console::LEVEL_WARN, ['ERROR', 'WARN']],
    [Console::LEVEL_INFO, ['ERROR', 'WARN', 'INFO']],
    [Console::LEVEL_DEBUG, ['ERROR', 'WARN', 'INFO', 'DEBUG']],
    [Console::LEVEL_TRACE, ['ERROR', 'WARN', 'INFO', 'DEBUG', 'TRACE']],
]);

test('console defaults hide debug and trace messages', function () {
    $output = captureConsoleOutput(function () {
        Console::info('server', 'Visible');
        Console::debug('server', 'Hidden debug');
        Console::trace('server', 'Hidden trace');
    });

    expect($output)->toContain('Visible');
    expect($output)->not->toContain('Hidden');
});

test('console listeners have independent verbosity and receive plain text', function () {
    $normal         = [];
    $debug          = [];
    $normalListener = function (string $line) use (&$normal): void {
        $normal[] = $line;
    };
    $debugListener = function (string $line) use (&$debug): void {
        $debug[] = $line;
    };
    Console::listen($normalListener);
    Console::listen($debugListener, Console::LEVEL_DEBUG);
    Console::setOutputLevel(Console::LEVEL_NONE);
    Console::setColors(true);

    $output = captureConsoleOutput(function () {
        Console::info('network', 'Connected');
        Console::debug('network', 'Sending map');
        Console::trace('network', 'Packet details');
    });

    expect($output)->toBe('');
    expect($normal)->toBe(['[INFO] [network]: Connected']);
    expect($debug)->toBe(['[INFO] [network]: Connected', '[DEBUG] [network]: Sending map']);
});

test('console listeners can change verbosity or unsubscribe without duplicates', function () {
    $lines    = [];
    $listener = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    Console::setOutputLevel(Console::LEVEL_NONE);
    Console::listen($listener);
    Console::debug('server', 'Hidden');
    Console::listen($listener, Console::LEVEL_DEBUG);
    Console::debug('server', 'Visible');
    Console::listen($listener, Console::LEVEL_NONE);
    Console::error('server', 'Disabled');
    Console::unlisten($listener);
    Console::listen($listener);
    Console::info('server', 'Resubscribed');
    Console::unlisten($listener);
    Console::error('server', 'Unsubscribed');

    expect($lines)->toBe(['[DEBUG] [server]: Visible', '[INFO] [server]: Resubscribed']);
});

test('console listeners are not kept alive by their subscriptions', function () {
    $listener  = function (string $line): void {};
    $reference = WeakReference::create($listener);
    Console::listen($listener);
    unset($listener);

    expect($reference->get())->toBeNull();
});

test('console messages and subsystem names cannot inject log lines or terminal escapes', function () {
    $lines    = [];
    $listener = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    Console::listen($listener);
    $output   = captureConsoleOutput(fn () => Console::warn("network\n\033", "name\nforged\r\0\033[31m\x7f\t🎮"));
    $expected = '[WARN] [network\\x0a\\x1b]: name\\x0aforged\\x0d\\x00\\x1b[31m\\x7f\\x09🎮';

    expect($lines)->toBe([$expected]);
    expect($output)->toBe('[2026-11-02 12:34:56] '.$expected.PHP_EOL);
});

test('console colors can be enabled explicitly', function () {
    Console::setColors(true);
    $output = captureConsoleOutput(fn () => Console::warn('server', 'Warning'));

    expect($output)->toBe("\033[0;33m[2026-11-02 12:34:56] [WARN] [server]: Warning\033[0m".PHP_EOL);
});

test('automatic console colors respect NO_COLOR', function () {
    $previous = getenv('NO_COLOR');
    putenv('NO_COLOR=1');
    Console::setColors(null);
    try {
        $output = captureConsoleOutput(fn () => Console::info('server', 'No colors'));
        expect($output)->not->toContain("\033");
    } finally {
        putenv($previous === false ? 'NO_COLOR' : 'NO_COLOR='.$previous);
    }
});

test('automatic console colors are disabled for redirected stdout', function () {
    $process = proc_open([
        PHP_BINARY, '-r',
        'require '.var_export(__DIR__.'/../../vendor/autoload.php', true).'; \\TeeFrame\\Server\\Console::info("server", "Redirected");',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the console test process');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, (string) $error);
    expect($output)->toContain('[INFO] [server]: Redirected');
    expect($output)->not->toContain("\033");
});

test('file logging appends plain timestamped lines with its own verbosity', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-console-');
    if ($path === false) {
        throw new RuntimeException('Could not create the console test log');
    }
    try {
        file_put_contents($path, 'Existing line'.PHP_EOL);
        Console::setOutputLevel(Console::LEVEL_NONE);
        Console::setColors(true);
        Console::setLogFile($path, Console::LEVEL_DEBUG);
        Console::debug('world', 'Map loaded');
        Console::trace('server', 'Not in file');
        Console::setLogFile(null);
        Console::error('server', 'File closed');
        Console::setLogFile($path, Console::LEVEL_WARN);
        Console::info('server', 'Below file verbosity');
        Console::warn('register', 'Registration pending');
        Console::setLogFile(null);

        expect(file_get_contents($path))->toBe(
            'Existing line'.PHP_EOL
            .'[2026-11-02 12:34:56] [DEBUG] [world]: Map loaded'.PHP_EOL
            .'[2026-11-02 12:34:56] [WARN] [register]: Registration pending'.PHP_EOL,
        );
    } finally {
        Console::setLogFile(null);
        unlink($path);
    }
});

test('failure to open a new log file leaves the current file in use', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-console-');
    if ($path === false) {
        throw new RuntimeException('Could not create the console test log');
    }
    try {
        Console::setOutputLevel(Console::LEVEL_NONE);
        Console::setLogFile($path);
        expect(fn () => Console::setLogFile($path.'/missing.log'))->toThrow(RuntimeException::class);
        Console::info('server', 'Still logging');
        Console::setLogFile(null);

        expect(file_get_contents($path))->toContain('[INFO] [server]: Still logging');
    } finally {
        Console::setLogFile(null);
        unlink($path);
    }
});

test('server constructor enables optional file logging before the server starts', function () {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-console-');
    if ($path === false) {
        throw new RuntimeException('Could not create the console test log');
    }
    try {
        new ConsoleTestServer(new TickHandler, 'Logging test', logFile: $path);
        captureConsoleOutput(fn () => Console::info('server', 'File logging enabled'));
        Console::setLogFile(null);

        expect(file_get_contents($path))->toBe('[2026-11-02 12:34:56] [INFO] [server]: File logging enabled'.PHP_EOL);
    } finally {
        Console::setLogFile(null);
        unlink($path);
    }
});

test('server constructor disables file logging when its path is omitted or empty', function (bool $explicit) {
    $path = tempnam(sys_get_temp_dir(), 'teeframe-console-');
    if ($path === false) {
        throw new RuntimeException('Could not create the console test log');
    }
    try {
        Console::setLogFile($path);
        captureConsoleOutput(fn () => Console::info('server', 'Before construction'));
        if ($explicit) {
            new ConsoleTestServer(new TickHandler, 'Logging test', logFile: '');
        } else {
            new ConsoleTestServer(new TickHandler, 'Logging test');
        }
        $output = captureConsoleOutput(fn () => Console::info('server', 'After construction'));

        expect(file_get_contents($path))->toBe('[2026-11-02 12:34:56] [INFO] [server]: Before construction'.PHP_EOL);
        expect($output)->toContain('After construction');
    } finally {
        Console::setLogFile(null);
        unlink($path);
    }
})->with([false, true]);

test('console output levels reject unsupported values', function (int $level) {
    $listener = function (string $line): void {};

    expect(fn () => Console::setOutputLevel($level))->toThrow(InvalidArgumentException::class);
    expect(fn () => Console::listen($listener, $level))->toThrow(InvalidArgumentException::class);
    expect(fn () => Console::setLogFile(null, $level))->toThrow(InvalidArgumentException::class);
})->with([-2, 5]);
