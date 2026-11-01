<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Core\Utf8;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RconCmdRemChunk extends AbstractChunk
{
    public function __construct(public string $name)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::RCON_CMD_REM, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)->addString(Utf8::truncateBytes($this->name, 63));
    }
}
