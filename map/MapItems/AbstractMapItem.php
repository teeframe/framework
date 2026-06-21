<?php

namespace TeeFrame\Map\MapItems;

abstract class AbstractMapItem
{
    public function __construct() {}

    abstract public static function make(int $id, int $size, string $data): static;
}
