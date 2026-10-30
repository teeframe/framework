<?php

namespace Tests\Support;

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Server\AbstractServerInstance;

class RecordingGameServer extends AbstractServerInstance
{
    /** @var list<int> */
    public array $sentTeeIndexes = [];

    /** @var list<AbstractChunk> */
    public array $sentChunks = [];

    /** @var list<array{world: AbstractWorld, teeIndex: int, chunk: AbstractChunk}> */
    public array $sentPairs = [];

    /** @var array<int, string> */
    public array $kickedTees = [];

    public function __construct(TickHandler $tickHandler)
    {
        parent::__construct($tickHandler, name: 'Recording server');
    }

    protected function boot(): void {}

    protected function selectWorldForNewConnection(): AbstractWorld
    {
        throw new \LogicException('Recording server does not accept connections');
    }

    public function sendToTee(AbstractWorld $world, int $teeIndex, AbstractChunk $chunk): void
    {
        $this->sentTeeIndexes[] = $teeIndex;
        $this->sentChunks[]     = $chunk;
        $this->sentPairs[]      = ['world' => $world, 'teeIndex' => $teeIndex, 'chunk' => $chunk];
    }

    public function kick(AbstractWorld $world, int $teeIndex, string $reason): void
    {
        $this->kickedTees[$teeIndex] = $reason;
    }

    public function clear(): void
    {
        $this->sentTeeIndexes = [];
        $this->sentChunks     = [];
        $this->sentPairs      = [];
        $this->kickedTees     = [];
    }
}
