<?php

namespace Game\Core;

use Game\Player;
use TeeFrame\Network\SnapItems\AbstractSnapItem;

interface SnapableObject
{
    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(Player $requestingPlayer): array;
}
