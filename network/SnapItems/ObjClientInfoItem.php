<?php

namespace TeeFrame\Network\SnapItems;

use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;

class ObjClientInfoItem extends AbstractSnapItem
{
    public function __construct(
        public string $name,
        public string $clan,
        public int $country,
        public string $skinName,
        public bool $useCustomColor,
        public int $colorBody,
        public int $colorFoot,
    ) {
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_CLIENTINFO);
    }

    public function getInts(): array
    {
        return [
            ...$this->convertStringToIntArray($this->name, 4), // TODO: Maybe this should be cached for performance?
            ...$this->convertStringToIntArray($this->clan, 3),
            $this->country,
            ...$this->convertStringToIntArray($this->skinName, 6),
            (int) $this->useCustomColor,
            $this->colorBody,
            $this->colorFoot,
        ];
    }

    /**
     * @param string $string Input string
     * @param int $num Number of output integers count
     * 
     * @return int[]
     */
    protected function convertStringToIntArray(string $string, int $num): array
    {
        $integers = array_fill(0, $num, 0);
        $bytes = unpack('c*', $string);
        $bytesCount = count($bytes);
        $index = 0;

        for ($i = 0; $i < $num; $i++) {
            $buffer = [0, 0, 0, 0];

            for (
                $c = 0;
                $c < 4 && $index < $bytesCount;
                $c++, $index++
            ) {
                $buffer[$c] = $bytes[$index + 1];
            }

            $integers[$i] = NetworkBase::toInt32(
                (($buffer[0] + 128) << 24) |
                (($buffer[1] + 128) << 16) |
                (($buffer[2] + 128) << 8) |
                (($buffer[3] + 128) << 0)
            );
        }

        $integers[$num - 1] = NetworkBase::toInt32($integers[$num - 1] & 0xffff_ff00);
        return $integers;
    }
}