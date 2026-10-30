<?php

namespace TeeFrame\Map;

class MapReader
{
    protected string $rawContent = '';
    /**
     * @var array<int, array{typeId: int, start: int, num: int}>
     */
    protected array $itemTypes = [];

    /**
     * @var array<int, array{typeId: int, id: int, size: int, data: string}>
     */
    protected array $items = [];

    /**
     * @var array<int, string>
     */
    protected array $dataBlocks = [];

    public function __construct(protected string $path) {}

    public function read(): void
    {
        $buffer = $this->readFile();
        $reader = new MapBufferReader($buffer);
        $header = $this->readHeader($reader, strlen($buffer));

        $itemTypes   = $this->readItemTypes($reader, $header['numItemTypes'], $header['numItems']);
        $itemOffsets = $reader->readInts($header['numItems']);
        $dataOffsets = $reader->readInts($header['numData']);
        $dataSizes   = $header['version'] === 4 ? $reader->readInts($header['numData']) : [];

        $this->validateOffsets($itemOffsets, $header['itemSize'], 'item');
        $this->validateOffsets($dataOffsets, $header['dataSize'], 'data');

        $items      = $this->readItems($reader->readBytes($header['itemSize']), $itemTypes, $itemOffsets);
        $dataBlocks = $this->readDataBlocks($reader->readBytes($header['dataSize']), $dataOffsets, $dataSizes, $header['version']);

        $this->rawContent = $buffer;
        $this->itemTypes  = $itemTypes;
        $this->items      = $items;
        $this->dataBlocks = $dataBlocks;
    }

    protected function readFile(): string
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $buffer = file_get_contents($this->path);
        } finally {
            restore_error_handler();
        }
        if ($buffer === false) {
            $this->throwException('Failed to read file');
        }

        return $buffer;
    }

    /**
     * @return array{version: int, numItemTypes: int, numItems: int, numData: int, itemSize: int, dataSize: int}
     */
    protected function readHeader(MapBufferReader $reader, int $fileSize): array
    {
        $magic   = $reader->readMagic();
        $version = $reader->readInt();

        if (($magic !== 'DATA' && $magic !== 'ATAD') || ($version !== 3 && $version !== 4)) {
            $this->throwException("Invalid file format or version ({$magic}, {$version})");
        }

        $size         = $reader->readInt();
        $swapLen      = $reader->readInt();
        $numItemTypes = $reader->readInt();
        $numItems     = $reader->readInt();
        $numData      = $reader->readInt();
        $itemSize     = $reader->readInt();
        $dataSize     = $reader->readInt();

        foreach ([$size, $swapLen, $numItemTypes, $numItems, $numData, $itemSize, $dataSize] as $value) {
            if ($value < 0) {
                $this->throwException('Negative datafile count or size');
            }
        }

        $itemsStart = 36 + $numItemTypes * 12 + $numItems * 4 + $numData * ($version === 4 ? 8 : 4);
        $dataStart  = $itemsStart + $itemSize;
        if ($size !== $fileSize - 16
            || $swapLen !== $dataStart - 16
            || $dataStart + $dataSize !== $fileSize
            || $itemSize % 4 !== 0
            || $numItems > intdiv($itemSize, 8)
        ) {
            $this->throwException('Invalid datafile section sizes');
        }

        return [
            'version'      => $version,
            'numItemTypes' => $numItemTypes,
            'numItems'     => $numItems,
            'numData'      => $numData,
            'itemSize'     => $itemSize,
            'dataSize'     => $dataSize,
        ];
    }

    /**
     * @return array<int, array{typeId: int, start: int, num: int}>
     */
    protected function readItemTypes(MapBufferReader $reader, int $numItemTypes, int $numItems): array
    {
        $itemTypes = [];
        $seenTypes = [];
        $nextItem  = 0;
        for ($i = 0; $i < $numItemTypes; $i++) {
            $typeId = $reader->readInt();
            $start  = $reader->readInt();
            $num    = $reader->readInt();
            if ($typeId < 0 || $typeId > 0xFFFF || isset($seenTypes[$typeId])
                || $start !== $nextItem || $num <= 0 || $num > $numItems - $start
            ) {
                $this->throwException('Invalid item type range or duplicate type');
            }
            $itemTypes[]        = ['typeId' => $typeId, 'start' => $start, 'num' => $num];
            $seenTypes[$typeId] = true;
            $nextItem += $num;
        }
        if ($nextItem !== $numItems) {
            $this->throwException('Item type ranges do not cover the items section');
        }

        return $itemTypes;
    }

    /**
     * @param  array<int, array{typeId: int, start: int, num: int}>  $itemTypes
     * @param  int[]  $itemOffsets
     * @return array<int, array{typeId: int, id: int, size: int, data: string}>
     */
    protected function readItems(string $itemBytes, array $itemTypes, array $itemOffsets): array
    {
        $itemSize = strlen($itemBytes);
        $items    = [];
        foreach ($itemTypes as $itemType) {
            $seenIds = [];
            for ($i = $itemType['start']; $i < $itemType['start'] + $itemType['num']; $i++) {
                $start = $itemOffsets[$i];
                $end   = $itemOffsets[$i + 1] ?? $itemSize;
                if ($start % 4 !== 0 || $end - $start < 8) {
                    $this->throwException("Invalid item offset at index {$i}");
                }
                $itemReader  = new MapBufferReader(substr($itemBytes, $start, $end - $start));
                $typeIdAndId = $itemReader->readInt();
                $payloadSize = $itemReader->readInt();
                $typeId      = ($typeIdAndId >> 16) & 0xFFFF;
                $id          = $typeIdAndId & 0xFFFF;
                if ($typeId !== $itemType['typeId'] || isset($seenIds[$id])
                    || $payloadSize < 0 || $payloadSize % 4 !== 0
                    || $payloadSize !== $itemReader->remaining()
                ) {
                    $this->throwException("Invalid item header at index {$i}");
                }
                $items[] = [
                    'typeId' => $typeId,
                    'id'     => $id,
                    'size'   => $payloadSize,
                    'data'   => $itemReader->readBytes($payloadSize),
                ];
                $seenIds[$id] = true;
            }
        }

        return $items;
    }

    /**
     * @param  int[]  $dataOffsets
     * @param  int[]  $dataSizes
     * @return array<int, string>
     */
    protected function readDataBlocks(string $rawData, array $dataOffsets, array $dataSizes, int $version): array
    {
        $dataSize   = strlen($rawData);
        $dataBlocks = [];
        foreach ($dataOffsets as $i => $start) {
            $end   = $dataOffsets[$i + 1] ?? $dataSize;
            $block = substr($rawData, $start, $end - $start);
            if ($version === 4) {
                $block = $this->decompressDataBlock($block, $dataSizes[$i], $i);
            }
            $dataBlocks[$i] = $block;
        }

        return $dataBlocks;
    }

    protected function decompressDataBlock(string $block, int $expectedSize, int $index): string
    {
        if ($expectedSize < 0) {
            $this->throwException("Negative uncompressed size for data block {$index}");
        }

        // Bound expansion and reject streams larger than the declared size.
        // Malformed streams report warnings; expose them as the exception below.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $block = zlib_decode($block, max(1, $expectedSize + 1));
        } finally {
            restore_error_handler();
        }
        if ($block === false || strlen($block) !== $expectedSize) {
            $this->throwException("Invalid compression or uncompressed size for data block {$index}");
        }

        return $block;
    }

    public function getRawContent(): string
    {
        return $this->rawContent;
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

    /**
     * @param  int[]  $offsets
     */
    protected function validateOffsets(array $offsets, int $sectionSize, string $section): void
    {
        if ($offsets === []) {
            if ($sectionSize !== 0) {
                $this->throwException("Nonempty {$section} section without offsets");
            }

            return;
        }

        $previous = 0;
        foreach ($offsets as $index => $offset) {
            if (($index === 0 && $offset !== 0) || $offset < $previous || $offset > $sectionSize) {
                $this->throwException("Invalid {$section} offset at index {$index}");
            }
            $previous = $offset;
        }
    }

    protected function throwException(string $message): never
    {
        throw new \RuntimeException("$this->path : {$message}");
    }
}
