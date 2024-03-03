<?php

namespace TeeFrame\Game\Core;

use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

interface SnapableObject
{
    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(AbstractTee $requestingTee): array;
}
