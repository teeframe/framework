<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

abstract class AbstractCommand
{
    public const TYPE_CHAT   = 1;
    public const TYPE_SERVER = 2;

    /** Bitmask of TYPE_CHAT and/or TYPE_SERVER (passed votes and authenticated RCON) */
    abstract public function getType(): int;

    abstract public function getName(): string;

    /** s: string, r: remaining text, i: integer, f: float; ? makes the remaining parameters optional. */
    abstract public function getParams(): string;

    /** @return list<string> */
    public function getAliases(): array
    {
        return [];
    }

    /** Description used by command help and RCON autocomplete. */
    public function getDescription(): string
    {
        return $this->getName();
    }

    public function getHelpText(): string
    {
        $aliases = array_map(
            fn (string $alias): string => $alias.($this->getParams() !== '' ? ' '.$this->getParams() : ''),
            $this->getAliases(),
        );

        return $this->getDescription().($aliases !== [] ? ' - Aliases: '.implode(', ', $aliases) : '');
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function formatList(array $names): array
    {
        $names = array_unique($names);
        sort($names, SORT_STRING | SORT_FLAG_CASE);
        $lines = [];
        $line  = '';
        foreach ($names as $name) {
            if ($line !== '' && strlen($line) + 2 + strlen($name) > 511) {
                $lines[] = $line;
                $line    = '';
            }
            $line .= ($line !== '' ? ', ' : '').$name;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Default invocation for argument-free vote options.
     * Options with arguments supply their command string when registered.
     */
    public function getCommand(): string
    {
        return $this->getName();
    }

    /** @param (\Closure(string): void)|null $output */
    protected function reply(AbstractWorld $world, AbstractTee $tee, string $message, ?\Closure $output = null): void
    {
        if ($output !== null) {
            $output($message);

            return;
        }

        $world->getServer()->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 0,
            clientId: -1,
            text: Utf8::truncateBytes($message, 511),
        ));
    }

    /**
     * @param  list<string>  $arguments
     * @param  (\Closure(string): void)|null  $output  Replies default to private chat.
     */
    abstract public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?\Closure $output = null): void;
}
