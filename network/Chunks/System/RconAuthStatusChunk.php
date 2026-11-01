<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RconAuthStatusChunk extends AbstractChunk
{
    public function __construct(public bool $authenticated, public bool $commandList)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::RCON_AUTH_STATUS, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractBool(), $payload->extractBool());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addBool($this->authenticated)
            ->addBool($this->commandList);
    }
}
