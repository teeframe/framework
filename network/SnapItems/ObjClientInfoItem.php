<?php

namespace Network\SnapItems;

use Network\NetworkMessages;

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
        parent::__construct(itemId: NetworkMessages::NETOBJTYPE_CLIENTINFO, integers: [
            ...$this->convertStringToInts($this->name, 16),
            ...$this->convertStringToInts($this->clan, 12),
            $this->country,
            ...$this->convertStringToInts($this->skinName, 24),
            (int) $this->useCustomColor,
            $this->colorBody,
            $this->colorFoot,
        ]);
    }

    protected function convertStringToInts(string $string, int $size): array
    {
        $string = str_pad(substr($string, 0, $size - 1), $size, "\0");

        // TODO: Refactor this
        $ints = [];
        $index = 0;
        $length = strlen($string);

        while ($length > 0) {
            $buf = [0, 0, 0, 0];
            for ($c = 0; $c < 4 && $index < strlen($string); $c++, $index++) {
                $buf[$c] = ord($string[$index]) + 128;
            }
            $result = ($buf[0] << 24) | ($buf[1] << 16) | ($buf[2] << 8) | $buf[3];
            $ints[] = $result;
            $length -= 4;
        }
    
        return $ints;
    }
}