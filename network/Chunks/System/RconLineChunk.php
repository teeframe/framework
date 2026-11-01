<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Core\Utf8;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RconLineChunk extends AbstractChunk
{
    public function __construct(public string $line)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::RCON_LINE, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)->addString(Utf8::truncateBytes(explode("\0", $this->line, 2)[0], 511));
    }
}
