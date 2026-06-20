<?php

namespace TeeFrame\Map;

use TeeFrame\Map\MapItems\AbstractMapItem;

class MapReader
{
    /**
     * @var array<int, array{typeId: int, start: int, num: int}>
     */
    private array $itemTypes = [];

    /**
     * @var array<int, array{typeId: int, id: int, size: int, data: string}>
     */
    private array $items = [];

    /**
     * @var array<int, string>
     */
    private array $dataBlocks = [];

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
        for ($i = 0; $i < $numItemTypes; $i++) {
            $this->itemTypes[] = [
                'typeId' => $reader->readInt(),
                'start'  => $reader->readInt(),
                'num'    => $reader->readInt(),
            ];
        }

        // Item Offsets
        $itemOffsets = $reader->readInts($numItems);

        // Data Offsets
        $dataOffsets = $reader->readInts($numData);

        // Data Sizes (version 4 only)
        $dataSizes = [];
        if ($version === 4) {
            $dataSizes = $reader->readInts($numData);
        }

        // Items
        foreach ($this->itemTypes as $itemType) {
            for ($i = 0; $i < $itemType['num']; $i++) {
                $typeIdAndId = $reader->readInt();
                $size        = $reader->readInt();

                $this->items[] = [
                    'typeId' => ($typeIdAndId >> 16) & 0xFFFF,
                    'id'     => $typeIdAndId         & 0xFFFF,
                    'size'   => $size,
                    'data'   => $reader->readBytes($size),
                ];
            }
        }

        // Data
        $rawData = $reader->readBytes($dataSize);

        if ($version === 4 && $dataSize > 0) {
            $rawData = zlib_decode($rawData);
            if ($rawData === false) {
                $this->throwException('Failed to decompress data section');
            }
        }

        for ($i = 0; $i < $numData; $i++) {
            $start  = $dataOffsets[$i];
            $length = ($i + 1 < $numData)
                ? $dataOffsets[$i + 1] - $start
                : strlen($rawData) - $start;

            $this->dataBlocks[$i] = substr($rawData, $start, $length);
        }
    }

    /**
     * @return array<int, array{typeId: int, start: int, num: int}>
     */
    public function getItemTypes(): array
    {
        return $this->itemTypes;
    }

    /**
     * @return array<int, array{typeId: int, id: int, size: int, data: string}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param  int  $typeId
     * @return array<int, array{typeId: int, id: int, size: int, data: string}>
     */
    public function getItemsByType(int $typeId): array
    {
        return array_filter(
            $this->items,
            fn (array $item) => $item['typeId'] === $typeId
        );
    }

    public function getDataBlock(int $index): ?string
    {
        return $this->dataBlocks[$index] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getDataBlocks(): array
    {
        return $this->dataBlocks;
    }

    /**
     * Get string data pointed to by an item data index.
     * Data indices come from item fields that reference data blocks.
     */
    public function getDataString(int $dataIndex): string
    {
        $data = $this->getDataBlock($dataIndex);

        if ($data === null) {
            return '';
        }

        $nullPos = strpos($data, "\0");
        if ($nullPos !== false) {
            $data = substr($data, 0, $nullPos);
        }

        return $data;
    }

    protected function throwException(string $message): never
    {
        throw new \RuntimeException("$this->path : {$message}");
    }
}