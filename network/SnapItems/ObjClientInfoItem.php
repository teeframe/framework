<?php

namespace Network\SnapItems;

use Network\RawPayload;

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
        parent::__construct(itemId: 11);
    }

    public function getPayload(): RawPayload
    {
        $rawPayload = new RawPayload;

        $nameInts = $this->convertStringToInts($this->name, 16);
        foreach ($nameInts as $nameInt) {
            $rawPayload->addInt($nameInt);
        }

        $clanInts = $this->convertStringToInts($this->clan, 12);
        foreach ($clanInts as $clanInt) {
            $rawPayload->addInt($clanInt);
        }

        $rawPayload->addInt($this->country);

        $skinNameInts = $this->convertStringToInts($this->skinName, 24);
        foreach ($skinNameInts as $skinNameInt) {
            $rawPayload->addInt($skinNameInt);
        }

        return $rawPayload
            ->addBool($this->useCustomColor)
            ->addInt($this->colorBody)
            ->addInt($this->colorFoot);
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