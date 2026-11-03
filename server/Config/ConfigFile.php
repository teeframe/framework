<?php

namespace TeeFrame\Server\Config;

use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\Vote\VoteOption;

class ConfigFile
{
    /**
     * @param  list<string>  $including
     * @param  class-string<Config>  $configClass
     * @param  list<string>  $loadedFiles
     * @return list<array{name: string, value: int|string}|WorldConfig|VoteOption>
     */
    public static function read(string $path, array $including = [], bool $worldOnly = false, string $configClass = Config::class, array &$loadedFiles = []): array
    {
        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new \RuntimeException('Cannot read configuration file: '.$path);
        }

        if (in_array($resolved, $including, true)) {
            throw new \UnexpectedValueException('Recursive configuration include: '.$resolved);
        }

        $including[] = $resolved;
        $file        = new \SplFileObject($resolved, 'rb');
        $entries     = [];
        $files       = [$resolved];

        for ($lineNumber = 0; ! $file->eof(); $lineNumber++) {
            $line = $file->fgets();

            if ($lineNumber === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            try {
                foreach (CommandLine::split($line) as $command) {
                    $name      = strtolower(CommandLine::name($command));
                    $arguments = substr($command, strlen($name));

                    if ($name === 'exec') {
                        [$included]  = CommandLine::parseArguments($arguments, 's[file]');
                        $includePath = self::resolvePath($resolved, $included);
                        $entries     = [...$entries, ...self::read($includePath, $including, $worldOnly, $configClass, $files)];

                        continue;
                    }

                    if ($name === 'add_vote') {
                        [$description, $voteCommand] = CommandLine::parseArguments($arguments, 's[name] r[command]');
                        $entries[]                   = new VoteOption($description, $voteCommand);

                        continue;
                    }

                    if ($name === 'sv_world_add') {
                        if ($worldOnly) {
                            throw new \InvalidArgumentException('World configs cannot add other worlds');
                        }

                        $arguments = CommandLine::parseArguments($arguments, WorldConfig::PARAMS);
                        $world     = WorldConfig::fromArguments($arguments, $configClass);

                        if (isset($arguments[5])) {
                            $world->settings->loadFile(self::resolvePath($resolved, $arguments[5]), worldOnly: true, including: $including);
                            $files = [...$files, ...$world->settings->getLoadedFiles()];
                        }

                        $entries[] = $world;

                        continue;
                    }

                    $definition = $configClass::definition($name);

                    if ($worldOnly && $definition['scope'] !== 'world') {
                        throw new \InvalidArgumentException('Server setting cannot be changed in a world config: '.$name);
                    }

                    [$value]   = CommandLine::parseArguments($arguments, is_int($definition['default']) ? 'i[value]' : 'r[value]');
                    $entries[] = ['name' => $name, 'value' => $configClass::validate($name, $value)];
                }
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                // Report the location, not the original line: it may contain a password.
                throw new \UnexpectedValueException($resolved.':'.($lineNumber + 1).': '.$exception->getMessage(), previous: $exception);
            }
        }

        $loadedFiles = [...$loadedFiles, ...$files];

        return $entries;
    }

    protected static function resolvePath(string $includingFile, string $path): string
    {
        return str_starts_with($path, '/') ? $path : dirname($includingFile).'/'.$path;
    }
}
