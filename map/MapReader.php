<?php

namespace Map;

use Map\MapItems\AbstractMapItem;

class MapReader
{
    public function __construct(protected string $path)
    {
    }

    public function read(): void
    {
        if (($buffer = file_get_contents($this->path)) === false) {
            $this->throwException('Failed to read file');
        }

        $reader = new MapBufferReader($buffer);

        // Version Header
        $magic   = $reader->readMagic();
        $version = $reader->readInt();

        if (($magic !== 'DATA' && $magic !== 'ATAD') || ($version !== 3 && $version !== 4)) {
            $this->throwException("Invalid file format or version ({$magic}, {$version})");
        }

        // Header
        $size         = $reader->readInt();
        $swapLen      = $reader->readInt();
        $numItemTypes = $reader->readInt();
        $numItems     = $reader->readInt();
        $numData      = $reader->readInt();
        $itemSize     = $reader->readInt();
        $dataSize     = $reader->readInt();

        // Item Types
        $itemTypes = [];
        for ($i=0; $i < $numItemTypes; $i++) { 
            $itemTypes[] = [
                $reader->readInt(), // Type ID
                $reader->readInt(), // Start
                $reader->readInt()  // Num
            ];
        }

        // Offsets
        $itemOffsets = $reader->readBytes($numItems * 4);
        $dataOffsets = $reader->readBytes($numData * 4);
        $dataSizes   = ($version === 4) ? $reader->readBytes($numData * 4) : null;

        // Items
        $items = [];
        foreach ($itemTypes as [$typeId, $start, $num]) {
            for ($i=0; $i < $num; $i++) { 
                $size = $reader->readInt();
                $data = $reader->readBytes($size);

                var_dump([$typeId, $size, strlen($data)]);

                // $items[] = $this->constructMapItem($typeId, $size, $data);
            }
        }

        // var_dump($items);
    }

    protected function constructMapItem(int $typeIdId, int $size, string $data): ?AbstractMapItem
    {
        $typeId = ($typeIdId  >> 16) & 0b1111_1111_1111_1111;
        $id     = $typeIdId & 0b1111_1111_1111_1111;

        return match($typeId) {
            0       => MapItems\VersionItem::make($id, $size, $data),
            default => null,
        };
    }

    protected function throwException(string $message): void
    {
        throw new \RuntimeException("$this->path : {$message}");
    }
}