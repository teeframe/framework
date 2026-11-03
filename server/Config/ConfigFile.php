<?php

namespace TeeFrame\Server\Config;

use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\Vote\VoteOption;
use TeeFrame\Server\Console;

class ConfigFile
{
    /**
     * @param  list<string>  $including
     * @param  class-string<Config>  $configClass
     * @param  list<string>  $loadedFiles
     * @return list<array{name: string, value: int|string}|WorldConfig|VoteOption>
     */
    public static function read(string $path, array $including = [], bool $worldOnly = false, string $configClass = Config::class, array &$loadedFiles = [], ?Config $config = null, bool $activatePvpSettings = true): array
    {
        $schema = $config ?? new $configClass;

        return self::readFile($path, $including, $worldOnly, $configClass, $loadedFiles, $schema, null, null, $activatePvpSettings);
    }

    /**
     * @param  list<string>  $including
     * @param  class-string<Config>  $configClass
     * @param  list<string>  $loadedFiles
     * @return list<array{name: string, value: int|string}|WorldConfig|VoteOption>
     */
    protected static function readFile(string $path, array $including, bool $worldOnly, string $configClass, array &$loadedFiles, Config $schema, ?string $sourceFile, ?int $sourceLine, bool $activatePvpSettings = true): array
    {
        $resolved = str_contains($path, "\0") ? false : realpath($path);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            self::report('configuration file "'.$path.'"', $sourceFile, $sourceLine, 'file is missing or unreadable');

            return [];
        }

        if (in_array($resolved, $including, true)) {
            self::report('configuration include "'.$resolved.'"', $sourceFile, $sourceLine, 'recursive configuration include');

            return [];
        }

        try {
            $file = new \SplFileObject($resolved, 'rb');
        } catch (\RuntimeException) {
            self::report('configuration file "'.$resolved.'"', $sourceFile, $sourceLine, 'cannot open file for reading');

            return [];
        }

        $including[] = $resolved;
        $entries     = [];
        $files       = [$resolved];

        for ($lineNumber = 1; ! $file->eof(); $lineNumber++) {
            try {
                $line = $file->fgets();
            } catch (\RuntimeException) {
                self::report('configuration file', $resolved, $lineNumber, 'cannot read file; remaining lines skipped');

                break;
            }

            if ($lineNumber === 1) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            try {
                $commands = CommandLine::split($line);
            } catch (\InvalidArgumentException) {
                self::report('configuration line', $resolved, $lineNumber, 'malformed command quoting; line skipped');

                continue;
            }

            foreach ($commands as $command) {
                $name      = strtolower(CommandLine::name($command));
                $arguments = substr($command, strlen($name));
                $subject   = match ($name) {
                    'exec'         => 'configuration include',
                    'add_vote'     => 'vote declaration',
                    'sv_world_add' => 'world declaration',
                    default        => 'configuration setting',
                };
                $reason = 'invalid command arguments';

                try {
                    if ($name === 'exec') {
                        [$included]  = CommandLine::parseArguments($arguments, 's[file]');
                        $includePath = self::resolvePath($resolved, $included);
                        $entries     = [...$entries, ...self::readFile($includePath, $including, $worldOnly, $configClass, $files, $schema, $resolved, $lineNumber, $activatePvpSettings)];

                        continue;
                    }

                    if ($name === 'add_vote') {
                        $reason                      = 'invalid vote arguments or description';
                        [$description, $voteCommand] = CommandLine::parseArguments($arguments, 's[name] r[command]');
                        $entries[]                   = new VoteOption($description, $voteCommand);

                        continue;
                    }

                    if ($name === 'sv_world_add') {
                        if ($worldOnly) {
                            self::report($subject, $resolved, $lineNumber, 'world configs cannot add other worlds');

                            continue;
                        }

                        $reason    = 'invalid world arguments, identifier, map or client limits';
                        $arguments = CommandLine::parseArguments($arguments, WorldConfig::PARAMS);
                        $world     = WorldConfig::fromArguments($arguments, $configClass, config: $schema);

                        if (isset($arguments[5])) {
                            $world->settings->loadFile(self::resolvePath($resolved, $arguments[5]), worldOnly: true, including: $including, activatePvpSettings: $activatePvpSettings);
                            $files = [...$files, ...$world->settings->getLoadedFiles()];
                        }

                        $entries[] = $world;

                        continue;
                    }

                    $reason     = 'unknown or unavailable configuration setting';
                    $definition = $schema->getDefinition($name, $activatePvpSettings);

                    if ($worldOnly && $definition['scope'] !== 'world') {
                        self::report($subject, $resolved, $lineNumber, 'server settings cannot be changed in a world config');

                        continue;
                    }

                    $reason    = is_int($definition['default']) ? 'expected an integer value with no extra arguments' : 'invalid string arguments';
                    [$value]   = CommandLine::parseArguments($arguments, is_int($definition['default']) ? 'i[value]' : 'r[value]');
                    $reason    = 'value fails type, range or format validation';
                    $entries[] = ['name' => $name, 'value' => $schema->validateValue($name, $value)];
                } catch (\InvalidArgumentException) {
                    // Validation exceptions and command names can contain secret input.
                    self::report($subject, $resolved, $lineNumber, $reason);
                }
            }
        }

        $loadedFiles = [...$loadedFiles, ...$files];

        return $entries;
    }

    protected static function report(string $subject, ?string $file, ?int $line, string $reason): void
    {
        $location = $file === null ? '' : ' at '.$file.($line === null ? '' : ':'.$line);

        Console::error('config', 'Cannot load '.$subject.$location.': '.$reason);
    }

    protected static function resolvePath(string $includingFile, string $path): string
    {
        return str_starts_with($path, '/') ? $path : dirname($includingFile).'/'.$path;
    }
}
