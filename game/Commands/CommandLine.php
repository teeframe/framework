<?php

namespace TeeFrame\Game\Commands;

class CommandLine
{
    /** @return list<string>|null */
    public static function match(AbstractCommand $command, string $line): ?array
    {
        $line   = ltrim($line);
        $length = strcspn($line, " \t\r\n\v\f");
        $name   = substr($line, 0, $length);
        if (strcasecmp($name, $command->getName()) !== 0
            && ! array_any($command->getAliases(), fn (string $alias): bool => strcasecmp($name, $alias) === 0)) {
            return null;
        }

        return self::arguments(substr($line, $length), $command->getParams());
    }

    /** @return list<string> */
    public static function arguments(string $line, string $params): array
    {
        $arguments = [];
        $offset    = 0;
        $length    = strlen($line);
        foreach (self::parameters($params) as $parameter) {
            $offset += strspn($line, " \t\r\n\v\f", $offset);
            if ($offset === $length) {
                if (! $parameter['optional']) {
                    throw new \InvalidArgumentException('Missing argument: '.$parameter['name']);
                }
                break;
            }

            $value = self::readArgument($line, $offset, $parameter['type'] === 'r');
            $valid = match ($parameter['type']) {
                'i'     => filter_var($value, FILTER_VALIDATE_INT) !== false,
                'f'     => filter_var($value, FILTER_VALIDATE_FLOAT) !== false && is_finite((float) $value),
                default => true,
            };
            if (! $valid) {
                throw new \InvalidArgumentException('Expected '.($parameter['type'] === 'i' ? 'an integer' : 'a float').' for '.$parameter['name']);
            }
            $arguments[] = $value;
        }

        $offset += strspn($line, " \t\r\n\v\f", $offset);
        if ($offset !== $length) {
            throw new \InvalidArgumentException('Too many command arguments');
        }

        return $arguments;
    }

    /** @return list<array{type: string, name: string, optional: bool}> */
    protected static function parameters(string $params): array
    {
        $parameters = [];
        $offset     = 0;
        $length     = strlen($params);
        $optional   = false;
        $rest       = false;
        while ($offset < $length) {
            $offset += strspn($params, " \t\r\n\v\f", $offset);
            if ($offset === $length) {
                break;
            }
            if ($rest || ! preg_match('/\G(\??)([sifr])(?:\[([^\]]+)\])?(?=\s|$)/', $params, $matches, 0, $offset)) {
                throw new \LogicException('Invalid command parameter format: '.$params);
            }
            $optional     = $optional || $matches[1] === '?';
            $rest         = $matches[2] === 'r';
            $parameters[] = [
                'type'     => $matches[2],
                'name'     => $matches[3] ?? 'argument '.(count($parameters) + 1),
                'optional' => $optional,
            ];
            $offset += strlen($matches[0]);
        }

        return $parameters;
    }

    protected static function readArgument(string $line, int &$offset, bool $rest): string
    {
        $length = strlen($line);
        if ($line[$offset] !== '"') {
            $size  = $rest ? $length - $offset : strcspn($line, " \t\r\n\v\f", $offset);
            $value = substr($line, $offset, $size);
            $offset += $size;

            return $value;
        }

        $value = '';
        $offset++;
        while ($offset < $length && $line[$offset] !== '"') {
            if ($line[$offset] === '\\' && $offset + 1 < $length && ($line[$offset + 1] === '"' || $line[$offset + 1] === '\\')) {
                $offset++;
            }
            $value .= $line[$offset++];
        }
        if ($offset === $length) {
            throw new \InvalidArgumentException('Unclosed quote in command line');
        }
        $offset++;
        if ($offset < $length && strspn($line, " \t\r\n\v\f", $offset, 1) === 0) {
            throw new \InvalidArgumentException('Expected whitespace after a quoted argument');
        }

        return $value;
    }

    /** @return list<non-empty-string> */
    public static function split(string $line): array
    {
        $commands = [];
        $start    = 0;
        $quoted   = false;
        $length   = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $character = $line[$i];
            if ($character === '\\' && $i + 1 < $length && ($line[$i + 1] === '"' || $line[$i + 1] === '\\')) {
                $i++;

                continue;
            }
            if ($character === '"') {
                $quoted = ! $quoted;

                continue;
            }
            if ($quoted) {
                continue;
            }
            if ($character === '#') {
                $length = $i;
                break;
            }
            if ($character === ';') {
                $command = trim(substr($line, $start, $i - $start));
                if ($command !== '') {
                    $commands[] = $command;
                }
                $start = $i + 1;
            }
        }

        if ($quoted) {
            throw new \InvalidArgumentException('Unclosed quote in command line');
        }
        $command = trim(substr($line, $start, $length - $start));
        if ($command !== '') {
            $commands[] = $command;
        }

        return $commands;
    }
}
