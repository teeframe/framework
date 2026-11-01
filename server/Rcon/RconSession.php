<?php

namespace TeeFrame\Server\Rcon;

class RconSession
{
    public bool $authenticated = false;
    public int $authTries      = 0;
    public bool $commandList   = false;

    /** @var array<string, array{help: string, params: string}> Autocomplete entries already sent to this client. */
    public array $advertisedCommands = [];
}
