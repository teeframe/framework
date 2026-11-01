<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Core\Utf8;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RconCmdAddChunk extends AbstractChunk
{
    public function __construct(public string $name, public string $help, public string $params)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::RCON_CMD_ADD, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractString(), $payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString(Utf8::truncateBytes($this->name, 63))
            ->addString(Utf8::truncateBytes($this->help, 191))
            ->addString(Utf8::truncateBytes($this->params, 95));
    }
}
