<?php

namespace Base\Connection\Concerns;

use Network\Encoder\PackageChunkEncoder;
use Network\Encoder\PackageEncoder;
use Network\Enums\Network;

trait HasPacketSending
{
    /**
     * @var array<int, PackageChunkEncoder>
     */
    public array $chunksQueue = [];

    public function resetChunksQueue(): void
    {
        $this->chunksQueue = [];
    }

    public function addChunk(PackageChunkEncoder $chunk): static
    {
        $this->chunksQueue[] = $chunk;

        if ($chunk->getFlags() & Network::CHUNKFLAG_VITAL) {
            $this->sequence++;

            $chunk->setSequence($this->sequence); // TODO: The maximum sequence is needed here?
        }

        return $this;
    }

    public function sendChunks(): bool
    {
        $encoder = new PackageEncoder(0, $this->ack, $this->chunksQueue);

        $this->resetChunksQueue();

        return $encoder->send($this->clientAddress, $this->clientPort);
    }

    public function sendControlMessage(int $message, string $extra = ''): bool
    {
        $encoder = PackageEncoder::makeControlMessage($message, $extra);

        return $encoder->send($this->clientAddress, $this->clientPort);
    }
}
