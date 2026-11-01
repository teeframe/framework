<?php

namespace TeeFrame\Network\Chunks\System;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

class RconAuthChunk extends AbstractChunk
{
    public function __construct(
        public string $name,
        #[\SensitiveParameter] public string $password,
        public bool $sendCommandList = true,
    ) {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::RCON_AUTH, isSystem: true);
    }

    public static function make(RawPayload $payload): static
    {
        return new static($payload->extractString(), $payload->extractString(), $payload->extractBool());
    }

    public function getPayload(): RawPayload
    {
        return (new RawPayload)
            ->addString($this->name)
            ->addString($this->password)
            ->addBool($this->sendCommandList);
    }
}
