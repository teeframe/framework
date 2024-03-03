<?php

namespace TeeFrame\Core;

interface TickableObject
{
    public function doTick(): void;
}
