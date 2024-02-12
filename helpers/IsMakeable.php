<?php

namespace Helpers;

trait IsMakeable
{
    public static function make(...$args)
    {
        return new static(...$args);
    }
}
