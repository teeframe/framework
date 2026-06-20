<?php

namespace TeeFrame\Map\MapItems;

use TeeFrame\Map\MapBufferReader;

class EnvPointsItem extends AbstractMapItem
{
    /**
     * @param  array<int, array{time: int, curveType: int, values: int[]}>  $points
     */
    public function __construct(
        public array $points,
    ) {
        parent::__construct();
    }

    /**
     * @param  int  $numPoints  Total number of envelope points across all envelopes
     */
    public static function make(int $id, int $size, string $data, int $numPoints = 0): static
    {
        $reader = new MapBufferReader($data);
        $points = [];

        // 0.6: each point is 6 i32s (time, curveType, 4 values)
        for ($i = 0; $i < $numPoints; $i++) {
            $time      = $reader->readInt();
            $curveType = $reader->readInt();
            $values    = $reader->readInts(4);

            $points[] = [
                'time'      => $time,
                'curveType' => $curveType,
                'values'    => $values,
            ];
        }

        return new static($points);
    }
}