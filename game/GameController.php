<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Core\SnapableObject;

class GameController implements SnapableObject
{
    /**
     * @return AbstractSnapItem[]
     */
    public function doSnap(Player $requestingPlayer): array
    {
        return [];
    }
}
