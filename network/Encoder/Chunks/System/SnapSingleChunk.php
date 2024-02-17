<?php

namespace Network\Encoder\Chunks\System;

use Network\Encoder\PackageChunkEncoder;
use Network\Encoder\PackageChunkSnapEncoder;
use Network\Enums\Protocol;

class SnapSingleChunk extends PackageChunkEncoder
{
    /**
     * @var array<int, PackageChunkSnapEncoder>
     */
    protected array $snaps = [];

    public static function make(int $currentTick, int $deltaTick): static
    {
        return (new static(0, Protocol::SNAPSINGLE))
            ->addInt($currentTick)
            ->addInt($deltaTick);
    }

    public function addSnap(PackageChunkSnapEncoder $snap): static
    {
        $this->snaps[] = $snap;

        return $this;
    }

    public function encode(): array
    {
        $encodedSnaps = [];
        foreach ($this->snaps as $snap) {
            $encodedSnaps = [...$encodedSnaps, ...$snap->encode()];
        }

        $this->payload[] = $this->calculateCrc(); // CRC
        $this->payload[] = count($encodedSnaps) + 3; // Size (+3 for Removed Items, Delta Number and Zero)
        $this->payload[] = 0; // Removed Items
        $this->payload[] = count($this->snaps); // Delta Number
        $this->payload[] = 0; // Zero
        $this->payload = [...$this->payload, ...$encodedSnaps];

        return parent::encode();
    }

    protected function calculateCrc(): int
    {
        $crc = 0;

        foreach ($this->snaps as $snap) {
            $payload = $snap->getPayload();

            for ($i=0; $i < count($payload); $i++) { 
                $crc += $payload[$i];
            }
        }

        return $crc;
    }
}
