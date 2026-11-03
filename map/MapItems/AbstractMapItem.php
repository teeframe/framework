<?php

namespace TeeFrame\Map\MapItems;

abstract class AbstractMapItem
{
    abstract public static function make(int $id, int $size, string $data): self;
}
