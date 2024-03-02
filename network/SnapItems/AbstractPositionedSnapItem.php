<?php

namespace Network\SnapItems;

abstract class AbstractPositionedSnapItem extends AbstractSnapItem
{
    /**
     * @param array<int, int> $integers
     */
    public function __construct(protected int $itemId, protected array $integers, public int $x, public int $y)
    {
        parent::__construct($itemId, $integers);
    }
}