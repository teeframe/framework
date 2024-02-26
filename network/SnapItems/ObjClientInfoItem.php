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

    public function getPayload(): array
    {
        return (new RawPayload)
            ->addString(substr($this->name, 0, 15))
            ->addString(substr($this->clan, 0, 11))
            ->addInt($this->country)
            ->addString(substr($this->skinName, 0, 19))
            ->addBool($this->useCustomColor)
            ->addInt($this->colorBody)
            ->addInt($this->colorFoot)
            ->getPayload();
    }
}
