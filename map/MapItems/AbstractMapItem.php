<?php

namespace TeeFrame\Map\MapItems;

abstract class AbstractMapItem
{
    public function __construct(
    ) {
    }

    // public function __construct(
    //     protected int $id,
    //     protected int $size,
    //     protected string $data,
    // ) {
    // }

    abstract public static function make(int $id, int $size, string $data): static;

    // public function getId(): int
    // {
    //     return $this->id;
    // }

    // public function getSize(): int
    // {
    //     return $this->size;
    // }
}
